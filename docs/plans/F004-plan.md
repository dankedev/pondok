# F004 keuangan-tarif — Jenis biaya & resolusi tarif

Status rencana: APPROVED

Owner: tim-agent-fase1. Dependensi: F002 (`done`), F003 (`done`, commit 2b90024).

## Ringkasan & tujuan

Bendahara bisa mengatur **jenis biaya** (SPP, asrama, makan, formulir, uang pangkal, dst.) beserta frekuensi, kelompok tagihan (`billing_group`), prioritas pelunasan, dan sasaran (`semua` / `mukim` / `nonmukim`), lalu mengatur **tarif** per jenjang, per angkatan, per asrama, dan (kelak) per gelombang PSB, berlaku mulai tahun ajaran tertentu. Inti fitur ini adalah Action `ResolveFeeRate` yang memilih satu baris tarif dari yang paling spesifik (angkatan + asrama → angkatan → jenjang + asrama → jenjang), lalu `effective_from_year` terbaru yang sudah berlaku. Action ini dipakai F006 (generator tagihan bulanan) dan F010/F012 (tagihan PSB). Semua perubahan jenis biaya dan tarif tercatat di Activitylog.

Acuan: PRD 2 (peran Bendahara), PRD 3.5, PRD 5 butir 3 (fallback tarif dan `effective_from_year`), ERD "Keuangan & Tagihan", "Biaya PSB" (tarif per gelombang), "Aturan tarif & constraint penting" (urutan penentuan nominal, unique `fee_rates`, catatan NULL pada unique key).

## Ruang lingkup

- Termasuk:
  - Migration, model, factory: `fee_types`, `fee_rates` (dengan generated column `cohort_key`, `dormitory_key`, `wave_key` dan unique portabel).
  - Action `SaveFeeType`, `SaveFeeRate`, `ResolveFeeRate` di `app/Actions/Keuangan/`.
  - Activitylog (`LogsActivity`, log name `keuangan`) pada `FeeType` dan `FeeRate`.
  - Panel Filament: `FeeTypeResource` dan `FeeRateResource` (halaman `Manage…`), grup navigasi baru "Keuangan".
  - Permission baru + policy Shield, pembagian ke `bendahara`; saklar `modules.keuangan.enabled` aktif bawaan.
  - Penyesuaian tombol Delete di resource Angkatan, Asrama, Tahun ajaran, dan Jenjang agar tidak memicu `QueryException` ketika baris itu dirujuk `fee_rates` (FK baru `restrictOnDelete`).
  - Teks `lang/id/pondok.php`.
- Tidak termasuk (eksplisit):
  - Tabel `admission_waves` dan FK `fee_rates.admission_wave_id` → `admission_waves` (F009). F004 hanya menyediakan kolom nullable tanpa FK beserta `wave_key`, dan resolver sudah mendukung parameter gelombang. Field gelombang di form tarif belum ditampilkan.
  - Beasiswa, `scholarships`, `fee_overrides`, dan penerapan mode nominal/potongan/persen (F005).
  - Invoices, invoice_items, generator bulanan, penyaringan `applies_to` mukim/nonmukim saat menagih, dan `invoice_items.rate_source` (F006). F004 hanya menyediakan data `applies_to` dan resolver.
  - Pembayaran dan alokasi cicilan berdasarkan `priority` (F007). F004 hanya menyimpan `priority`.
  - Widget dashboard keuangan (F018).
  - Seeder jenis biaya contoh/bawaan, dan alat "Cek tarif santri" (simulasi resolusi dari panel). Bila diinginkan, usulkan entri baru (lihat Risiko).
  - Menghapus jenis biaya atau tarif dari UI (data keuangan tidak dihapus; jenis biaya dinonaktifkan lewat `is_active`, tarif dikoreksi lewat Edit atau diganti baris tahun berikutnya).
  - Cakupan jenjang lewat global scope pada `FeeRate` (lihat Permission & cakupan; hanya penyaringan di resource).

## Model data

Semua tabel memakai `timestamps`. Tidak ada soft delete. Uang `unsignedBigInteger` rupiah. Semua FK `restrictOnDelete` (data keuangan).

### `fee_types` — model `App\Models\FeeType`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| code | string(30), unique | huruf besar, angka, garis bawah (`SPP`, `ASRAMA`, `UANG_PANGKAL`); dinormalisasi oleh Action |
| name | string | "SPP", "Uang pangkal" |
| frequency | enum `bulanan`, `tahunan`, `saat_daftar`, `saat_diterima`, `manual` | |
| applies_to | enum `semua`, `mukim`, `nonmukim`, default `semua` | |
| billing_group | string(30) | huruf besar, angka, garis bawah; sama = digabung dalam satu tagihan |
| priority | unsignedSmallInteger, default 100 | angka lebih kecil dilunasi lebih dulu saat cicilan |
| is_active | boolean, default true | |

Index (`frequency`, `is_active`) untuk generator F006. Konstanta `FREQUENCIES`, `APPLIES_TO`, plus konstanta tiap nilai (`FREQUENCY_MONTHLY = 'bulanan'`, `APPLIES_TO_RESIDENT = 'mukim'`, dst.). Cast `priority` integer, `is_active` boolean. Relasi `rates()` HasMany(`FeeRate`). Scope `active()`.

### `fee_rates` — model `App\Models\FeeRate`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| fee_type_id | foreignId → fee_types, `restrictOnDelete` | |
| effective_from_year_id | foreignId → academic_years, `restrictOnDelete` | berlaku mulai tahun ajaran ini |
| unit_id | foreignId → units, `restrictOnDelete` | wajib |
| cohort_id | foreignId → cohorts, nullable, `restrictOnDelete` | null = default jenjang |
| dormitory_id | foreignId → dormitories, nullable, `restrictOnDelete` | null = semua asrama / nonmukim |
| admission_wave_id | unsignedBigInteger, nullable, index | **tanpa FK** sampai F009 menambahkan tabel `admission_waves` dan constraint-nya (pola `students.applicant_id`). Komentar migration menyebut ini |
| amount | unsignedBigInteger | rupiah, boleh 0 |
| cohort_key | unsignedBigInteger, generated **stored** `COALESCE(cohort_id, 0)` | tidak di `$fillable`, tidak ditulis aplikasi |
| dormitory_key | unsignedBigInteger, generated stored `COALESCE(dormitory_id, 0)` | idem |
| wave_key | unsignedBigInteger, generated stored `COALESCE(admission_wave_id, 0)` | idem |

- Unique (`fee_type_id`, `effective_from_year_id`, `unit_id`, `cohort_key`, `dormitory_key`, `wave_key`) dengan **nama eksplisit** `fee_rates_combination_unique` (nama bawaan melebihi batas 64 karakter MySQL).
- Index (`fee_type_id`, `unit_id`) untuk query resolver.
- Migration memakai `->storedAs('COALESCE(cohort_id, 0)')` dst. (pola `room_assignments.active_marker`), SQL standar yang jalan di SQLite, MySQL/MariaDB, dan Postgres.
- Cast `amount` integer. Relasi `feeType()`, `effectiveFromYear()` (BelongsTo `AcademicYear`), `unit()`, `cohort()`, `dormitory()`. Tidak ada relasi `admissionWave()` di F004.
- Method `isCohortRate(): bool` (`cohort_id !== null`) agar F006 bisa mengisi `rate_source` (`angkatan` / `jenjang`) tanpa logika ulang.

### Activitylog
`FeeType` dan `FeeRate` memakai `Spatie\Activitylog\Traits\LogsActivity` dengan `getActivitylogOptions()`:
- `useLogName('keuangan')`
- `logOnly(<daftar fillable>)` (kolom generated `*_key` dan timestamps tidak dicatat)
- `logOnlyDirty()` dan `dontSubmitEmptyLogs()`
Event `created`, `updated`, `deleted` tercatat otomatis; causer = user login (null bila dari command/scheduler). Tidak ada perubahan config Activitylog.

### Tambahan pada model yang sudah ada
- `AcademicYear`, `Unit`, `Cohort`, `Dormitory`: relasi `feeRates()` HasMany (`AcademicYear` lewat `effective_from_year_id`). Dipakai untuk syarat tampil Delete.
- Factory baru: `FeeTypeFactory` (state `monthly()`, `resident()` (`applies_to = mukim`), `nonResident()`, `inactive()`; code unik acak huruf besar), `FeeRateFactory` (`for` fee type, academic year, unit; `amount` kelipatan 1000; state `forCohort(Cohort)`, `forDormitory(Dormitory)`, `forWave(int $waveId)`).

## Action class

Semua di `app/Actions/Keuangan/`. Kegagalan melempar `ValidationException` dengan pesan `pondok.keuangan.errors.<reason>` (pola `SaveRoom` / `EnrollStudent`).

### `SaveFeeType::handle(array $data, ?FeeType $feeType = null): FeeType`
`$data`: `code`, `name`, `frequency`, `applies_to`, `billing_group`, `priority`, `is_active`. Field error sesuai nama kolom.
1. `code` dan `billing_group` di-`trim` dan diubah ke huruf besar; spasi di `billing_group` diganti `_`.
2. `code` tidak cocok `^[A-Z0-9_]{1,30}$` → `code_format`; `billing_group` tidak cocok pola yang sama → `billing_group_format`.
3. `code` sudah dipakai jenis biaya lain → `code_taken` (DB unique tetap pengaman terakhir).
4. `frequency` di luar `FREQUENCIES` → `invalid_frequency`; `applies_to` di luar `APPLIES_TO` → `invalid_applies_to`.
5. `priority` bukan bilangan bulat 0–65535 → `priority_range`.
6. Edit: `applies_to` diubah menjadi `nonmukim` padahal jenis biaya ini punya tarif dengan `dormitory_id` terisi → `has_dormitory_rates`.
7. Simpan dan kembalikan model. Efek samping: satu baris activity (`created`/`updated`).

### `SaveFeeRate::handle(array $data, ?FeeRate $feeRate = null): FeeRate`
`$data`: `fee_type_id`, `effective_from_year_id`, `unit_id`, `cohort_id` (nullable), `dormitory_id` (nullable), `admission_wave_id` (nullable, tidak dikirim UI di F004), `amount`. Field error sesuai nama kolom.
1. `amount` harus bilangan bulat `>= 0` (int, atau string digit saja); selain itu (negatif, desimal, kosong) → `amount_invalid`. Disimpan sebagai int.
2. `cohort_id` terisi dan `cohort.unit_id !== unit_id` → `cohort_unit_mismatch`.
3. `dormitory_id` terisi dan jenis biaya `applies_to = nonmukim` → `dormitory_rate_for_nonresident_fee`.
4. Sudah ada baris lain (selain `$feeRate`) dengan kombinasi `fee_type_id`, `effective_from_year_id`, `unit_id`, `cohort_id`, `dormitory_id`, `admission_wave_id` yang sama (pembandingan null-aman: `whereNull` bila null) → `duplicate_rate` (field `effective_from_year_id`). Unique `fee_rates_combination_unique` tetap pengaman terakhir.
5. Simpan dalam `DB::transaction`. Efek samping: satu baris activity.
`admission_wave_id` tidak divalidasi keberadaannya di F004 (tabel belum ada); F009 menambahkan validasi itu.

### `ResolveFeeRate`
Mencari satu tarif yang berlaku. Murni baca; tidak memakai cakupan jenjang user login (resolver dipanggil scheduler tanpa user).

`handle(FeeType $feeType, AcademicYear $academicYear, int $unitId, ?int $cohortId = null, ?int $dormitoryId = null, ?int $admissionWaveId = null): ?FeeRate`

`forStudent(FeeType $feeType, Student $student, AcademicYear $academicYear): ?FeeRate` → memanggil `handle` dengan `$student->unit_id`, `$student->cohort_id`, `$student->activeRoom?->dormitory_id`, wave null.

Algoritma (satu query kandidat, peringkat di PHP agar portabel):
1. Kandidat = `fee_rates` dengan `fee_type_id` sama, `unit_id` sama, `cohort_id` ∈ {`$cohortId`, NULL}, `dormitory_id` ∈ {`$dormitoryId`, NULL}, `admission_wave_id` ∈ {`$admissionWaveId`, NULL}, dan tahun berlaku **sudah berlaku**: join `academic_years` dengan `academic_years.starts_on <= $academicYear->starts_on` (tahun ajaran target ikut). Bila parameter null, hanya baris yang kolomnya NULL.
2. Tingkat spesifisitas (nilai lebih kecil menang):
   - 1 angkatan + asrama (`cohort_id` dan `dormitory_id` terisi)
   - 2 angkatan (`cohort_id` terisi, `dormitory_id` null)
   - 3 jenjang + asrama (`cohort_id` null, `dormitory_id` terisi)
   - 4 jenjang (keduanya null)
3. Ambil tingkat terbaik yang punya kandidat. **Tingkat menang atas tahun**: tarif angkatan 2025 tetap menang atas tarif jenjang 2026 (ERD: "Tarif angkatan selalu menang atas default jenjang").
4. Di dalam tingkat itu: baris dengan `admission_wave_id` cocok menang atas baris gelombang null; lalu `starts_on` tahun berlaku terbaru; lalu `id` terbesar sebagai pemutus seri.
5. Tidak ada kandidat → `null` (F006 mencatat "tarif belum diatur" dan melewati santri).
Resolver tidak memeriksa `fee_types.is_active`, `frequency`, maupun `applies_to`; penyaringan itu tanggung jawab pemanggil (F006/F010).

## UI (Filament / Blade)

Rujukan design-guide: §0 (checklist), §3 (ikon `Banknotes` untuk keuangan; tidak ada ikon baru), §4 (tidak ada status baru; `frequency`/`applies_to` tampil sebagai teks label, bukan badge), §5 (uang `->money('IDR', locale: 'id', decimalPlaces: 0)`, input `TextInput` numeric + `->prefix('Rp')`, kosong `-`, tahun ajaran dari `academic_years.name`), §6 (halaman `Manage…`, ≤7 kolom, kolom uang rata kanan `->alignEnd()` dan `tabular-nums`, filter jenjang `->accessible()`), §7 (empty state, notifikasi danger/success), §8 (data keuangan tidak punya tombol Hapus; label kata kerja + objek), §9. Implementer wajib memanggil `/anti-ui-slop` bila tersedia dan menjalankan checklist §0.

Grup navigasi baru `pondok.navigation.keuangan` = "Keuangan". Kedua resource memakai `BelongsToModule` dengan `getModule(): 'keuangan'`.

### `FeeTypeResource` — "Jenis biaya" (`app/Filament/Resources/FeeTypes/`)
Halaman tunggal `ManageFeeTypes`. Ikon `Banknotes`, navigationSort 1.
- Form (modal, 2 kolom): `code` (helper "Huruf besar tanpa spasi, contoh SPP"), `name`, `frequency` (Select, label `pondok.fee_type.frequencies.*`), `applies_to` (Select, label `pondok.fee_type.applies_to_options.*`, default `semua`), `billing_group` (default `BULANAN`, helper "Jenis biaya dengan kelompok sama digabung dalam satu tagihan"), `priority` (integer, default 100, helper "Angka kecil dilunasi lebih dulu saat cicilan"), `is_active` (Toggle).
- Create dan Edit memanggil `SaveFeeType` lewat `->using(...)`; `ValidationException` tampil sebagai error field tanpa menyimpan.
- Tabel: `code`, `name`, `frequency` (label), `applies_to` (label), `billing_group`, `priority` (rata kanan), `is_active` (IconColumn); `rates_count` toggleable tersembunyi. `defaultSort('priority')` lalu `code`. Filter `frequency`, `applies_to`, `is_active` (TernaryFilter).
- Aksi baris: Edit saja. Tidak ada Delete maupun bulk delete.
- Empty state: `pondok.fee_type.empty` = "Belum ada jenis biaya. Tambahkan SPP, asrama, atau biaya lain."

### `FeeRateResource` — "Tarif" (`app/Filament/Resources/FeeRates/`)
Halaman tunggal `ManageFeeRates`. Ikon `Banknotes`, navigationSort 2.
- `getEloquentQuery()`: `whereIn('unit_id', Unit::query()->accessible()->select('id'))` (pola `CohortResource`).
- Form (modal, 2 kolom): `fee_type_id` (Select searchable; saat create hanya jenis biaya aktif), `effective_from_year_id` (Select tahun ajaran urut `starts_on` turun, default tahun aktif, label "Berlaku mulai tahun ajaran"), `unit_id` (jenjang `accessible()`, `live`), `cohort_id` (opsional, opsi angkatan milik jenjang terpilih, placeholder "Semua angkatan"), `dormitory_id` (opsional, asrama aktif, placeholder "Semua asrama"; disembunyikan bila jenis biaya `applies_to = nonmukim`), `amount` (numeric integer, `minValue(0)`, `prefix('Rp')`). Helper di bawah form: "Bila tarif naik, tambahkan tarif baru dengan tahun ajaran berikutnya. Tarif lama tetap tersimpan sebagai riwayat."
- Create dan Edit memanggil `SaveFeeRate` lewat `->using(...)`; `ValidationException` tampil sebagai error field.
- Tabel: `feeType.name`, `unit.name`, `cohort.name` (placeholder `pondok.fee_rate.all_cohorts` = "Semua angkatan"), `dormitory.name` (placeholder `pondok.fee_rate.all_dormitories` = "Semua asrama"), `effectiveFromYear.name` ("Berlaku mulai"), `amount` (money, `alignEnd`). Eager-load `feeType`, `unit`, `cohort`, `dormitory`, `effectiveFromYear`. `defaultSort` jenis biaya → jenjang → tahun berlaku terbaru. Filter jenis biaya, jenjang (`accessible()`), tahun berlaku.
- Aksi baris: Edit saja. Tidak ada Delete.
- Empty state: `pondok.fee_rate.empty` = "Belum ada tarif. Tambahkan tarif default per jenjang terlebih dahulu."

### Penyesuaian Delete di resource lain (FK baru `restrictOnDelete`)
Tombol Delete disembunyikan bila baris dirujuk `fee_rates`:
- `CohortResource`: tersembunyi bila `students_count > 0` **atau** punya `feeRates`.
- `DormitoryResource::canBeDeleted()` dan `DormitoriesTable`: tambah syarat tidak punya `feeRates`.
- `AcademicYearResource`: tersembunyi bila aktif **atau** dipakai sebagai `effective_from_year_id`.
- `UnitResource`: tersembunyi bila punya `feeRates` (syarat lain untuk Unit di luar lingkup F004, lihat Risiko).
Gunakan `withCount`/`withExists` di `modifyQueryUsing` agar tidak N+1.

### Teks `lang/id/pondok.php` (kunci baru)
`navigation.keuangan`; `fee_type.*` (label "Jenis biaya", plural, code, code_helper, name, frequency, frequencies [bulanan="Bulanan", tahunan="Tahunan", saat_daftar="Saat daftar", saat_diterima="Saat diterima", manual="Manual"], applies_to, applies_to_options [semua="Semua santri", mukim="Mukim", nonmukim="Nonmukim"], billing_group, billing_group_helper, priority, priority_helper, rates_count, empty); `fee_rate.*` (label "Tarif", plural, fee_type, effective_from_year "Berlaku mulai", unit, cohort, dormitory, amount "Nominal", all_cohorts, all_dormitories, new_year_helper, empty); `keuangan.errors.*` (code_format, billing_group_format, code_taken, invalid_frequency, invalid_applies_to, priority_range, has_dormitory_rates, amount_invalid, cohort_unit_mismatch, dormitory_rate_for_nonresident_fee, duplicate_rate). Pesan menyebut cara memperbaiki (§9), contoh `duplicate_rate`: "Tarif untuk kombinasi jenis biaya, tahun ajaran, jenjang, angkatan, dan asrama ini sudah ada. Ubah tarif yang ada atau pilih tahun ajaran lain."

## Permission & cakupan

### Permission baru
Tambah `'FeeType', 'FeeRate'` ke `RolePermissionSeeder::RESOURCE_MODELS`, lalu:
`php artisan shield:generate --option=policies --resource=FeeTypeResource,FeeRateResource --panel=admin -n`
(bila Shield tidak membuat policy, salin pola `CohortPolicy`). Nama: `view-any:fee-type`, `create:fee-rate`, dst.

### Pembagian ke peran
| Peran | Permission keuangan |
|---|---|
| super-admin | semua (otomatis) |
| bendahara | `FeeType` dan `FeeRate`: `viewAny`, `view`, `create`, `update` (tanpa delete; data keuangan tidak dihapus) |
| peran lain | tidak ada |

Dataset `bendahara` di `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` menjadi persis: `view-dashboard`, `view-finance-metrics`, `view-any:fee-type`, `view:fee-type`, `create:fee-type`, `update:fee-type`, `view-any:fee-rate`, `view:fee-rate`, `create:fee-rate`, `update:fee-rate`. Dataset peran lain tidak berubah. Seeder memakai variabel `$withoutDelete` yang sudah ada; komentar seeder diperbarui.

### Cakupan jenjang
`FeeType` lintas jenjang, tidak dibatasi. `FeeRate` **tidak** memakai `HasUnitScope` (konfigurasi, dan resolver harus melihat semua tarif tanpa bergantung user login); daftar tarif di panel disaring lewat `getEloquentQuery()` dan opsi jenjang `accessible()`.

### Saklar modul
`SettingSeeder::ENABLED_BY_DEFAULT` menjadi `['akademik', 'asrama', 'keuangan']`. Saat `modules.keuangan.enabled` false: halaman `FeeTypeResource` dan `FeeRateResource` 403 dan grup "Keuangan" hilang. Action tetap bisa dipanggil dari kode.

## Kriteria penerimaan (bisa dites)

Fixture resolver bersama (dipakai AC-1 – AC-12): jenjang SLTP; tahun ajaran 2024/2025 (mulai 2024-07-15), 2025/2026 (mulai 2025-07-15), 2026/2027 (mulai 2026-07-15, aktif), 2027/2028 (mulai 2027-07-15); angkatan 2025 SLTP; asrama D1 dan D2; jenis biaya `ASRAMA` (bulanan, mukim).

Resolusi tarif — urutan fallback
- AC-1: Ada empat tarif tahun 2025/2026 untuk SLTP: jenjang Rp400.000, jenjang + D1 Rp450.000, angkatan 2025 Rp500.000, angkatan 2025 + D1 Rp550.000 → `handle(ASRAMA, 2026/2027, SLTP, angkatan2025, D1)` mengembalikan baris Rp550.000.
- AC-2: Fixture AC-1 tanpa baris angkatan + asrama → hasil Rp500.000 (angkatan).
- AC-3: Hanya baris jenjang + D1 Rp450.000 dan jenjang Rp400.000 → untuk santri angkatan 2025 di D1 hasil Rp450.000.
- AC-4: Hanya baris jenjang Rp400.000 → santri angkatan 2025 di D1 mendapat Rp400.000; santri nonmukim (dormitory null) mendapat Rp400.000.
- AC-5: Tarif dengan asrama lain tidak pernah dipakai: dengan baris jenjang + D2 Rp600.000 dan jenjang Rp400.000, santri di D1 mendapat Rp400.000 dan santri nonmukim mendapat Rp400.000. Bila hanya ada baris jenjang + D2, santri nonmukim mendapat `null`.
- AC-6: Tarif angkatan lain tidak pernah dipakai: hanya ada baris angkatan 2024 Rp300.000 dan jenjang Rp400.000 → santri angkatan 2025 mendapat Rp400.000. Tarif jenjang lain (SD) tidak pernah dipakai untuk santri SLTP.
- AC-7: Tidak ada baris yang cocok → `null`.

Resolusi tarif — `effective_from_year`
- AC-8: Tarif jenjang 2024/2025 Rp350.000 dan 2025/2026 Rp400.000 → target 2026/2027 mendapat Rp400.000 (terbaru yang sudah berlaku); target 2024/2025 mendapat Rp350.000.
- AC-9: Tarif jenjang 2027/2028 Rp450.000 (belum berlaku) dan 2025/2026 Rp400.000 → target 2026/2027 mendapat Rp400.000. Bila hanya ada baris 2027/2028 → `null`.
- AC-10: Tarif yang berlaku mulai tahun target ikut dihitung: baris 2026/2027 Rp420.000 dan 2025/2026 Rp400.000 → target 2026/2027 mendapat Rp420.000.
- AC-11: Spesifisitas menang atas tahun: angkatan 2025 tahun 2025/2026 Rp500.000 dan jenjang tahun 2026/2027 Rp600.000 → santri angkatan 2025 dengan target 2026/2027 mendapat Rp500.000; santri angkatan lain (2026) mendapat Rp600.000.
- AC-12: Urutan tahun berdasarkan `academic_years.starts_on`, bukan `id`: tahun 2025/2026 dibuat setelah 2026/2027 (id lebih besar), tarif jenjang keduanya ada → target 2026/2027 tetap mendapat tarif 2026/2027.

Resolusi tarif — gelombang & santri
- AC-13: `admission_wave_id`: tarif jenjang gelombang 7 Rp150.000 dan jenjang tanpa gelombang Rp200.000 → `handle(..., admissionWaveId: 7)` mendapat Rp150.000; gelombang 8 mendapat Rp200.000; tanpa gelombang (null) mendapat Rp200.000. Baris gelombang 7 saja (tanpa baris umum) → panggilan tanpa gelombang mendapat `null`. Tarif angkatan tanpa gelombang tetap menang atas tarif jenjang bergelombang (tingkat menang atas gelombang).
- AC-14: `forStudent` memakai `unit_id`, `cohort_id`, dan asrama dari penempatan aktif santri: santri mukim di D1 mendapat tarif jenjang + D1; setelah `VacateRoom`, santri yang sama mendapat tarif jenjang. Resolver mengembalikan hasil yang sama saat dipanggil tanpa user login maupun oleh user yang cakupan jenjangnya tidak mencakup jenjang santri.
- AC-15: `FeeRate::isCohortRate()` true untuk baris dengan `cohort_id`, false untuk baris jenjang.

Simpan jenis biaya (`SaveFeeType`)
- AC-16: Data valid dengan `code` " spp " dan `billing_group` "bulanan" → tersimpan dengan `code = 'SPP'`, `billing_group = 'BULANAN'`, `priority` 100 bila tidak diberikan, `applies_to` sesuai input.
- AC-17: Gagal (tidak ada perubahan di DB) dengan dataset: `code` "SP P!" → `code_format`; `code` yang sudah dipakai → `code_taken`; `frequency` "harian" → `invalid_frequency`; `applies_to` "tamu" → `invalid_applies_to`; `priority` -1 atau 70000 → `priority_range`; `billing_group` "" → `billing_group_format`.
- AC-18: Jenis biaya yang punya tarif ber-asrama diubah `applies_to`-nya menjadi `nonmukim` → gagal `has_dormitory_rates`; tanpa tarif ber-asrama → berhasil.

Simpan tarif (`SaveFeeRate`)
- AC-19: Data valid → baris tersimpan dengan `amount` integer; `amount` 0 diterima; `amount` "1500000" (string digit) disimpan sebagai `1500000`.
- AC-20: Gagal dengan dataset: `amount` -1, `amount` "1500.50", `amount` null → `amount_invalid`; angkatan milik jenjang lain → `cohort_unit_mismatch`; asrama untuk jenis biaya `nonmukim` → `dormitory_rate_for_nonresident_fee`. Tidak ada baris baru.
- AC-21: Kombinasi sama (termasuk `cohort_id`, `dormitory_id`, `admission_wave_id` null) sudah ada → gagal `duplicate_rate`. Kombinasi yang hanya berbeda tahun berlaku, angkatan, asrama, atau gelombang → berhasil. Edit baris itu sendiri tanpa mengubah kombinasi → berhasil (tidak bentrok dengan dirinya).

Constraint database
- AC-22: Insert langsung (tanpa Action) dua tarif jenjang default (cohort, dormitory, wave semua null) untuk jenis biaya, tahun, dan jenjang yang sama → `UniqueConstraintViolationException`. Hal sama untuk duplikat dengan `cohort_id` terisi, dan dengan `admission_wave_id` terisi (`wave_key` ikut unique). Kombinasi berbeda wave (null vs 7) diizinkan. Factory `FeeRateFactory` membuat data tanpa menulis kolom `*_key`, dan setelah `refresh()` nilai `cohort_key` = `cohort_id` atau 0.
- AC-23: Menghapus jenis biaya, tahun ajaran, jenjang, angkatan, atau asrama yang dirujuk `fee_rates` melempar `QueryException` dan data tetap ada. `fee_types.code` ganda → `UniqueConstraintViolationException`. Kolom `fee_rates.amount` bertipe integer (bukan float/decimal) menurut `Schema::getColumnType` (`integer`/`bigint`).

Activitylog
- AC-24: Bendahara login membuat tarif lewat `SaveFeeRate` → tepat satu baris `activity_log` dengan `log_name = 'keuangan'`, `event = 'created'`, `subject` = tarif itu, `causer_id` = bendahara, dan `properties.attributes.amount` = nominal. Properti tidak memuat `cohort_key`, `dormitory_key`, `wave_key`, `created_at`, `updated_at`.
- AC-25: Mengubah `amount` tarif dari 400000 ke 450000 → activity `updated` dengan `properties.old.amount = 400000` dan `properties.attributes.amount = 450000`. Menyimpan ulang tanpa perubahan → tidak ada activity baru.
- AC-26: Membuat dan mengubah jenis biaya lewat `SaveFeeType` menghasilkan activity `created` dan `updated` dengan `log_name = 'keuangan'` dan subject jenis biaya itu.

Akses, cakupan, saklar modul (HTTP/Livewire)
- AC-27: `RolePermissionSeeder` memberi bendahara persis daftar di bagian Permission; dataset peran lain tidak berubah; test "creates every permission that a generated policy checks" tetap lulus.
- AC-28: Bendahara: `GET /admin/fee-types` dan `/admin/fee-rates` → 200. Peran admin-sekolah, kesantrian, musyrif, admin-konten, admin-psb, ustadz-tahfidz, petugas-pos → 403 pada keduanya.
- AC-29: `modules.keuangan.enabled = false` → super-admin mendapat 403 di kedua URL dan `/admin` tidak menampilkan `pondok.navigation.keuangan`; saat aktif teks itu tampil. Instalasi baru (`SettingSeeder` di DB kosong) menghasilkan `modules.keuangan.enabled = true`, dan `akademik`, `asrama` tetap aktif.
- AC-30: Bendahara dengan cakupan jenjang SLTP hanya melihat tarif SLTP di `ManageFeeRates` (`assertCanSeeTableRecords` / `assertCanNotSeeTableRecords`), dan opsi jenjang di form hanya SLTP.

UI aksi (Livewire)
- AC-31: Bendahara membuat jenis biaya dari `ManageFeeTypes` (code "spp") → tersimpan "SPP"; membuat dengan code yang sudah ada → error field `code` tampil, tidak ada baris baru. Aksi Delete tidak ada di tabel (`assertTableActionDoesNotExist('delete')`) dan tidak ada bulk delete.
- AC-32: Bendahara membuat tarif dari `ManageFeeRates` (amount 450000) → tersimpan integer 450000 dan kolom nominal tampil "Rp 450.000"; membuat duplikat → error `duplicate_rate` tampil, jumlah baris tetap; mengedit nominal → tersimpan dan activity `updated` tercatat. Aksi Delete tidak ada.
- AC-33: Tombol Delete tersembunyi (`assertTableActionHidden('delete', ...)`) untuk angkatan, asrama, tahun ajaran (tidak aktif), dan jenjang yang dirujuk `fee_rates`; tetap tampil untuk yang tidak dirujuk dan tidak punya rujukan lain yang sudah dicek sebelumnya.

## Rencana test

Catatan: helper Pest bersifat global. Jangan mendefinisikan ulang helper yang ada (`userWithRole`, `asrama*`, `classroomFor`, dst.); pakai awalan `keuangan` (mis. `keuanganUser()`, `keuanganYears()`, `keuanganFeeType()`, `keuanganRate()`).

| AC | File | Nama test yang diusulkan |
|---|---|---|
| AC-1 – AC-7 | `tests/Feature/Actions/Keuangan/ResolveFeeRateTest.php` | `it resolves the most specific rate` (dataset: cohort+dormitory, cohort, unit+dormitory, unit); `it never uses a rate for another dormitory`; `it never uses a rate for another cohort or unit`; `it returns null without a matching rate` |
| AC-8 – AC-12 | idem | `it picks the latest effective year that has started`; `it ignores rates that start in a later year`; `it includes rates starting in the target year`; `it prefers specificity over a newer year`; `it orders years by start date` |
| AC-13 – AC-15 | idem | `it prefers the admission wave rate and falls back to all waves`; `it resolves a student rate from the active room`; `it resolves the same rate regardless of the signed-in user scope`; `it tells whether a rate is a cohort rate` |
| AC-16 – AC-18 | `tests/Feature/Actions/Keuangan/SaveFeeTypeTest.php` | `it normalizes and saves a fee type`; `it rejects the fee type` (dataset); `it rejects switching to nonmukim when dormitory rates exist` |
| AC-19 – AC-21 | `tests/Feature/Actions/Keuangan/SaveFeeRateTest.php` | `it saves a rate as integer rupiah`; `it rejects the rate` (dataset); `it rejects a duplicate rate combination`; `it allows rates that differ in one dimension`; `it updates a rate without clashing with itself` |
| AC-22, AC-23 | `tests/Feature/Database/KeuanganConstraintsTest.php` | `it rejects duplicate default rates through generated keys`; `it includes the wave key in the unique rate`; `it fills generated keys without the application writing them`; `it restricts deleting records referenced by rates`; `it rejects a duplicate fee type code`; `it stores money as an integer column` |
| AC-24 – AC-26 | `tests/Feature/Models/KeuanganActivityLogTest.php` | `it logs rate creation with the causer`; `it logs only dirty rate changes`; `it logs fee type changes` |
| AC-27 | `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (ubah dataset) | dataset `bendahara` di `grants each role exactly its permissions` |
| AC-28, AC-29 | `tests/Feature/Filament/KeuanganAccessTest.php` | `it lets bendahara manage fee types and rates`; `it forbids keuangan resources to other roles` (dataset); `it returns 403 and hides the menu when the keuangan module is disabled`; `it enables the keuangan module on a fresh install` |
| AC-30, AC-32 | `tests/Feature/Filament/Resources/FeeRates/FeeRateResourceTest.php` | `it limits rates and unit options to the user units`; `it creates a rate from the panel`; `it shows the duplicate rate error`; `it edits a rate and logs the change`; `it has no delete action` |
| AC-31 | `tests/Feature/Filament/Resources/FeeTypes/FeeTypeResourceTest.php` | `it creates a fee type from the panel`; `it shows the taken code error`; `it has no delete action` |
| AC-33 | `tests/Feature/Filament/Resources/FeeRateReferencesTest.php` | `it hides delete for records referenced by rates` (dataset: cohort, dormitory, academic year, unit) |

## Urutan implementasi

1. Migration `fee_types`, `fee_rates` (generated stored `*_key`, unique bernama `fee_rates_combination_unique`, `admission_wave_id` tanpa FK) + model + factory + relasi `feeRates()` di model lama + Activitylog pada kedua model. Test: AC-22, AC-23, AC-15, AC-24 – AC-26 (bagian model; AC-24/26 setelah langkah 3 bila memakai Action).
2. Action `ResolveFeeRate` (+ `forStudent`). Test: AC-1 – AC-14.
3. Action `SaveFeeType`, `SaveFeeRate` + kunci `pondok.keuangan.errors.*`. Test: AC-16 – AC-21, AC-24 – AC-26.
4. Permission: `RESOURCE_MODELS`, peran bendahara, update `RolePermissionSeederTest`, `SettingSeeder::ENABLED_BY_DEFAULT`. Test: AC-27, bagian fresh install AC-29.
5. `FeeTypeResource` + policy Shield + lang + grup navigasi. Test: bagian `/admin/fee-types` dari AC-28/AC-29, AC-31.
6. `FeeRateResource` + policy + lang. Test: bagian `/admin/fee-rates` dari AC-28/AC-29, AC-30, AC-32.
7. Syarat Delete di Cohort/Dormitory/AcademicYear/Unit. Test: AC-33.
8. Pint, checklist design-guide §0, jalankan seluruh test terkait (termasuk test Akademik/Asrama yang menyentuh tombol Delete).

## Risiko & pertanyaan terbuka

Pertanyaan (default dipakai agar kerja lanjut tanpa menunggu user):
1. **Urutan gelombang vs spesifisitas.** Default: tingkat (angkatan+asrama → angkatan → jenjang+asrama → jenjang) tetap urutan utama; gelombang hanya pemecah di dalam tingkat yang sama (baris gelombang cocok menang atas baris tanpa gelombang). Alasan: menjaga aturan ERD "tarif angkatan selalu menang". Pada PSB, pendaftar umumnya belum punya angkatan/asrama, sehingga hasil praktisnya sama dengan "gelombang dulu". F009/F010 bisa meninjau ulang.
2. **Spesifisitas vs tahun.** Default: tingkat lebih spesifik menang walau tarif jenjang lebih baru (AC-11), sesuai contoh ERD angkatan 2025 terkunci Rp500.000. Konsekuensi: untuk menaikkan tarif angkatan yang sudah punya baris khusus, bendahara harus menambah baris angkatan baru.
3. **"Sudah berlaku"** dibandingkan dengan `academic_years.starts_on` tahun target (≤), bukan tanggal hari ini. Default ini membuat generator F006 deterministik terhadap periode tagihan.
4. **Hapus jenis biaya/tarif.** Default: tidak ada tombol Hapus untuk bendahara maupun super-admin di UI; jenis biaya dinonaktifkan, tarif dikoreksi lewat Edit (tercatat di Activitylog). Super-admin tetap memegang permission delete secara teknis (otomatis `allPermissions()`), tetapi tidak ada aksinya.
5. **Modul keuangan aktif bawaan sejak F004** walau tagihan/pembayaran belum ada. Default: ya, agar bendahara bisa menyiapkan tarif lebih dulu (pola modul asrama saat F003).
6. **Prioritas bawaan** 100, angka kecil dilunasi lebih dulu. Default ini diteruskan ke F007.
7. **Admin PSB perlu mengatur tarif gelombang?** Default: tidak; bendahara yang mengatur semua tarif. Ditinjau di F009.
8. **Peran lain melihat tarif (lihat-saja)?** Default: tidak ada (admin-sekolah tidak mendapat akses keuangan).

Risiko:
- Generated column `COALESCE` stored harus didukung SQLite ≥ 3.31, MySQL ≥ 5.7 / MariaDB ≥ 10.2, Postgres ≥ 12 (pola sama dengan `room_assignments.active_marker`, sudah lulus di SQLite).
- F009 akan menambah FK `admission_wave_id` ke tabel yang punya generated column. Di SQLite, Laravel membangun ulang tabel saat menambah FK; implementer F009 wajib memastikan kolom generated dan unique ikut terbawa (bila tidak, buat ulang lewat migration). Dicatat agar plan F009 menanganinya.
- Nama index unique bawaan melebihi 64 karakter di MySQL; wajib nama eksplisit `fee_rates_combination_unique`.
- Activitylog: `logOnly` harus memakai daftar fillable eksplisit; `logAll()` akan ikut mencatat kolom generated.
- `UnitResource` saat ini menampilkan Delete tanpa syarat walau jenjang punya santri/angkatan (celah lama di luar F004). F004 hanya menambah syarat `fee_rates`; usulan entri baru `core-hapus-aman` (cek semua rujukan sebelum menampilkan Delete untuk Unit dan AcademicYear) — belum ditambahkan ke `feature_list.json`, menunggu keputusan user.
- Usulan entri baru opsional (belum ditambahkan): `keuangan-cek-tarif` — aksi "Cek tarif santri" untuk bendahara yang menampilkan hasil `ResolveFeeRate` per jenis biaya; dan `keuangan-seed-jenis-biaya` — jenis biaya contoh saat instalasi.

## Riwayat revisi

- DRAFT 1 (product-manager): rencana awal. Jumlah AC: 33.

## Review #1 — 2026-10-15

Verdict: APPROVED

Semua acceptance di `feature_list.json` (urutan fallback teruji, uang `unsignedBigInteger`, Activitylog) tercakup AC-1 sampai AC-15, AC-22/23, dan AC-24 sampai AC-26. Dependensi F002 dan F003 `done`. Resolusi tarif konsisten dengan ERD ("Tarif angkatan selalu menang atas default jenjang", "berlaku mulai ... sampai ada baris yang lebih baru") dan CLAUDE.md. Pola generated column mengikuti `room_assignments.active_marker`. Tidak ada blocker atau major.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Risiko, rebuild SQLite oleh F009 | F009 `depends_on` hanya F001, jadi plan F009 tidak otomatis membaca catatan risiko di dokumen F004. Menambah FK ke tabel ber-generated column di SQLite membangun ulang tabel, dan salin data/unique bisa gagal. | Tambahkan catatan di `notes` entri F009 yang merujuk risiko ini, dan wajibkan test F009 yang memeriksa `cohort_key`/`dormitory_key`/`wave_key` dan unique `fee_rates_combination_unique` masih ada setelah migration. Alternatif: F009 hanya menambah FK di MySQL/Postgres dan memakai validasi Action di SQLite. |
| 2 | minor | AC-23, `Schema::getColumnType` | Nama tipe berbeda antar driver (SQLite `integer`, MySQL `bigint`, Postgres `int8`). Selain itu perlu dipastikan API ini tersedia di versi Laravel terpasang. | Assert dengan `Schema::getColumns` dan terima `integer`, `bigint`, `int8`. Jangan menguji unsigned (tidak ada di Postgres). |
| 3 | minor | AC-33, Penyesuaian Delete | Syarat sembunyi Delete memakai relasi `feeRates` dan harus berlaku walau `modules.keuangan.enabled` false, karena FK tetap ada. Plan belum menyatakannya. `UnitResource` dan `AcademicYearResource` kini menampilkan Delete tanpa syarat (`UnitResource.php:83`, `AcademicYearResource.php:106`). | Implementer menambah satu test: saklar keuangan mati, Delete tetap tersembunyi untuk baris yang dirujuk tarif. Pakai `withExists`, bukan `withCount` bila hanya perlu boolean. |
| 4 | minor | Algoritma resolver, langkah 1 | Perbandingan `academic_years.starts_on <=` dengan target aman dan portabel, tetapi dua tahun ajaran bisa punya `starts_on` sama (tidak unik) sehingga seri jatuh ke `id`. | Cukup dicatat. Tidak perlu constraint baru di F004. |
| 5 | minor | `forStudent`, AC-14 | Plan memakai `$student->activeRoom?->dormitory_id`. `activeRoom` (HasOneThrough, `Student.php:87`) mengembalikan `Room`, dan `dormitory_id` ada di `rooms`. Sudah benar, tetapi memicu satu query per santri bila F006 memanggil massal. | Biarkan. F006 boleh eager-load `activeRoom` atau memakai `handle()` langsung dengan dormitory yang sudah dihitung. |

Catatan tambahan: pengelolaan `activitylog` belum dipakai model mana pun (belum ada preseden). Implementer memastikan `logOnly` memakai daftar fillable eksplisit seperti di plan, dan AC-24 memeriksa `causer_id` saat login.
