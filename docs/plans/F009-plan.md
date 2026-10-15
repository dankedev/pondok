# F009 psb-master — Master PSB: gelombang, jalur, syarat berkas

Status rencana: APPROVED

Owner: diklaim orkestrator di `feature_list.json`. Dependensi: F001 (`done`). Dipakai oleh F004 (kolom `fee_rates.admission_wave_id` yang sudah ada) dan menjadi fondasi F010–F012.

## Ringkasan & tujuan

Admin PSB bisa menyiapkan data induk penerimaan santri baru sebelum pendaftaran dibuka:

- **Gelombang** (`admission_waves`): per tahun ajaran tujuan, dengan tanggal buka–tutup, kuota opsional, dan saklar buka/tutup manual.
- **Jalur pendaftaran** (`admission_tracks`): Reguler, Prestasi, Tahfidz, Yatim (di-seed), masing-masing bisa dihubungkan ke program beasiswa (`scholarships`, F005) untuk potongan otomatis saat diterima (dieksekusi di F011, bukan di sini).
- **Syarat berkas** (`admission_requirements`): per jenjang atau semua jenjang, merujuk ke jenis dokumen (`document_types`) agar berkas valid nanti bisa disalin ke dokumen santri.

Selain itu F009 menutup utang F004: menambah FK `fee_rates.admission_wave_id → admission_waves` (`restrictOnDelete`) tanpa merusak kolom generated dan unique `fee_rates_combination_unique`, serta menampilkan field gelombang di form tarif untuk jenis biaya PSB.

Semua di belakang saklar `modules.psb.enabled` (menu hilang dan halaman 403 bila mati), yang menjadi aktif secara bawaan karena panelnya kini ada.

Acuan: PRD 2 (Admin PSB: gelombang, jalur), PRD 3.4 butir 1, PRD 3.5 butir 2 (tarif per gelombang); ERD "Penerimaan Santri Baru (PSB)" (`admission_waves`, `admission_tracks`, `admission_requirements`), "Biaya PSB" (tarif per gelombang, jalur ↔ beasiswa), "Dokumen" (`document_types`, catatan "Berkas PSB tidak perlu diunggah ulang"), "Catatan NULL pada unique key".

## Keputusan desain: `document_types`

ERD menulis `admission_requirements.document_type_id` sebagai FK **wajib** (bukan nullable). Dua pilihan dari orkestrator:

- (a) F009 membuat tabel `document_types` lengkap sesuai ERD (8 kolom, tanpa UI), F013 memakainya.
- (b) `document_type_id` nullable tanpa FK sampai F013.

**Dipilih (a).** Alasan:

1. Sesuai ERD (FK wajib, tidak nullable). Pilihan (b) menyimpang dari ERD dan memaksa F013 mengubah kolom menjadi NOT NULL + menambah FK pada tabel yang mungkin sudah berisi NULL. Itu migrasi data dan perombakan tabel SQLite lagi, persis risiko yang sekarang harus kita tangani di `fee_rates`.
2. `document_types` berdiri sendiri (tanpa FK keluar), kecil, dan definisinya sudah final di ERD. Membuatnya sekarang tidak mengunci keputusan F013 tentang `documents`, verifikasi, atau unduhan.
3. Syarat berkas tanpa jenis dokumen tidak bisa dipetakan ke dokumen santri saat daftar ulang (F012), jadi (b) menunda masalah ke fitur yang lebih berisiko.

Batas yang dijaga: F009 hanya membuat migration, model, factory, dan seeder `document_types`. **Tidak ada** resource Filament, permission, atau policy `DocumentType`; itu tetap milik F013. Admin PSB memilih jenis dokumen dari daftar hasil seed. Orkestrator perlu menambah catatan di F013 bahwa tabel `document_types` sudah ada (lihat Laporan).

## Ruang lingkup

- Termasuk:
  - Migration, model, factory: `document_types`, `admission_waves`, `admission_tracks`, `admission_requirements`.
  - Migration terpisah: FK `fee_rates.admission_wave_id → admission_waves.id` `restrictOnDelete`.
  - Seeder `DocumentTypeSeeder` (7 jenis) dan `AdmissionTrackSeeder` (4 jalur), dipanggil `DatabaseSeeder`.
  - `SettingSeeder::ENABLED_BY_DEFAULT` ditambah `psb`.
  - Action di `app/Actions/Psb/`: `SaveAdmissionWave`, `SaveAdmissionTrack`, `SaveAdmissionRequirement`.
  - Perubahan `SaveFeeRate` (validasi gelombang) dan `FeeRateResource` (field + kolom gelombang).
  - Resource Filament (halaman `Manage…` modal): `AdmissionWaveResource`, `AdmissionTrackResource`, `AdmissionRequirementResource`, grup navigasi baru "PSB".
  - Permission + policy (Shield) untuk `AdmissionWave`, `AdmissionTrack`, `AdmissionRequirement`; peran `admin-psb`.
  - Penjaga hapus: jenjang dengan syarat berkas dan tahun ajaran dengan gelombang tidak bisa dihapus dari UI.
  - Activitylog (log name `psb`) pada `AdmissionWave` dan `AdmissionTrack`.
  - Scope/helper model untuk F010: gelombang yang menerima pendaftaran pada suatu tanggal, syarat berkas untuk satu jenjang.
  - Penyesuaian test lama F004 yang memakai `admission_wave_id` fiktif (7, 8) karena FK baru (lihat Rencana test).
- Tidak termasuk (eksplisit):
  - `applicants`, `applicant_documents`, form publik, nomor pendaftaran, cek status (F010).
  - Penegakan kuota gelombang (butuh `applicants`, F010). F009 hanya menyimpan `quota`.
  - Pembuatan keringanan otomatis dari `admission_tracks.scholarship_id` (F011), termasuk keputusan jenis biaya mana yang mendapat keringanan.
  - Resource/permission `DocumentType`, tabel `documents`, `issued_letters` (F013).
  - Seed syarat berkas bawaan (syarat berbeda per sekolah; diatur admin).
  - Widget dashboard PSB (`view-psb-metrics`, F016 atau fitur dashboard).
  - Perubahan `ResolveFeeRate` (sudah mendukung `admissionWaveId` sejak F004).

## Model data

Urutan migration (timestamp setelah `2026_10_15_214537_create_payment_allocations_table`):

1. `..._create_document_types_table`
2. `..._create_admission_waves_table`
3. `..._create_admission_tracks_table`
4. `..._create_admission_requirements_table`
5. `..._add_admission_wave_foreign_key_to_fee_rates_table`

### `document_types` — model `App\Models\DocumentType` (milik modul Dokumen, dibuat lebih awal)

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| code | string(30), unique | `KK`, `AKTA`, … huruf besar |
| name | string | |
| owner | enum `santri`, `wali`, `staff`, `prestasi` | konstanta `DocumentType::OWNERS`, `OWNER_STUDENT = 'santri'` |
| is_required | boolean default false | wajib untuk santri aktif (dipakai F013) |
| has_expiry | boolean default false | |
| is_confidential | boolean default false | |
| is_active | boolean default true | |
| timestamps | | |

Tanpa soft delete, tanpa activitylog. Cast boolean. Scope `active()`, scope `forStudents()` (`owner = santri`). Relasi `admissionRequirements(): HasMany`.

### `admission_waves` — model `App\Models\AdmissionWave`

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| academic_year_id | foreignId → academic_years, `restrictOnDelete` | tahun ajaran tujuan |
| name | string(100) | "Gelombang 1" |
| opens_on | date | |
| closes_on | date | `>= opens_on` (divalidasi Action) |
| quota | unsignedInteger nullable | NULL = tanpa batas |
| is_open | boolean default true | saklar manual |
| timestamps | | |

Index/constraint: unique (`academic_year_id`, `name`) — tambahan di luar ERD, aman karena tidak ada kolom nullable. Tanpa soft delete. Cast `opens_on`/`closes_on` `date`, `quota` integer, `is_open` boolean. Relasi `academicYear()`, `feeRates(): HasMany`. Activitylog `psb`, `logOnly` fillable, `logOnlyDirty`, `dontSubmitEmptyLogs`.

Helper untuk F010:
- `scopeAcceptingRegistrationsOn(Builder $query, CarbonInterface $date)`: `is_open = true` dan `whereDate('opens_on', '<=', $date)` dan `whereDate('closes_on', '>=', $date)` (batas inklusif, `Y-m-d`).
- `isAcceptingRegistrationsOn(CarbonInterface $date): bool` dengan aturan sama.
- Kuota **tidak** diperiksa di sini (F010).

`AcademicYear` ditambah relasi `admissionWaves(): HasMany`.

### `admission_tracks` — model `App\Models\AdmissionTrack`

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| code | string(30), unique | `REGULER`, `PRESTASI`, `TAHFIDZ`, `YATIM` |
| name | string | |
| scholarship_id | foreignId nullable → scholarships, `restrictOnDelete` | potongan otomatis (F011) |
| terms | text nullable | syarat jalur |
| is_active | boolean default true | |
| timestamps | | |

Tanpa soft delete. Relasi `scholarship()`. `Scholarship` ditambah `admissionTracks(): HasMany`. Scope `active()`. Activitylog `psb`.

### `admission_requirements` — model `App\Models\AdmissionRequirement`

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| unit_id | foreignId nullable → units, `restrictOnDelete` | NULL = semua jenjang |
| document_type_id | foreignId → document_types, `restrictOnDelete` | |
| name | string | "Kartu Keluarga" |
| is_required | boolean default true | |
| sort_order | unsignedTinyInteger default 0 | |
| unit_key | unsignedBigInteger `storedAs('COALESCE(unit_id, 0)')` | pola generated column CLAUDE.md |
| timestamps | | |

Unique (`document_type_id`, `unit_key`) bernama `admission_requirements_type_unit_unique`. Aturan "syarat semua jenjang dan syarat per jenjang untuk jenis dokumen yang sama tidak boleh berdampingan" dijaga di Action (DB tidak bisa mengekspresikannya). Tanpa soft delete, tanpa activitylog, tanpa global scope `unit_user` (lihat Permission & cakupan). Relasi `unit()`, `documentType()`. `Unit` ditambah `admissionRequirements(): HasMany`.

Helper untuk F010: `scopeForUnit(Builder $query, int $unitId)`: `unit_id IS NULL OR unit_id = $unitId`, urut `sort_order`, lalu `id`.

### FK `fee_rates.admission_wave_id`

Migration `Schema::table('fee_rates', fn (Blueprint $t) => $t->foreign('admission_wave_id')->references('id')->on('admission_waves')->restrictOnDelete())`. `down()`: `dropForeign(['admission_wave_id'])`.

- MySQL/Postgres: `ALTER TABLE ADD CONSTRAINT`, index `fee_rates_admission_wave_id_index` yang sudah ada dipakai ulang.
- SQLite: Laravel membangun ulang tabel (`compileAlter`). `BlueprintState` membaca ekspresi `generation` sehingga kolom generated seharusnya ikut, tetapi **wajib dibuktikan oleh test** (AC-2, AC-3), sesuai catatan F009 di `feature_list.json`. Bila test menunjukkan kolom generated hilang atau menjadi kolom biasa, green-coder membuat cabang khusus SQLite di migration yang membuat ulang tabel secara manual dengan definisi identik (tanpa mengubah test).
- Tidak ada pembersihan data yatim: sejak F004 form tarif tidak menampilkan gelombang, sehingga di produksi semua nilai `admission_wave_id` NULL.

`FeeRate` ditambah relasi `admissionWave(): BelongsTo`, dan PHPDoc kelas diperbarui (gelombang kini ber-FK).

## Action class

Semua Action melempar `ValidationException::withMessages([$field => __("pondok.psb.errors.{$reason}")])` (pola `SaveScholarship`). Tanggal dinormalisasi ke string `Y-m-d` sebelum `fill` agar deteksi dirty dan activitylog stabil (pelajaran review F005 #5). Parsing tanggal ketat `Y-m-d` (`Carbon::createFromFormat('!Y-m-d')` + cek format ulang).

### `App\Actions\Psb\SaveAdmissionWave::handle(array $data, ?AdmissionWave $wave = null): AdmissionWave`

Input: `academic_year_id`, `name`, `opens_on`, `closes_on`, `quota?`, `is_open?`.

Urutan validasi (gagal pertama dilempar, tanpa perubahan DB):
1. `academic_year_id` harus ada → `academic_year_id`/`academic_year_not_found`.
2. `name` di-trim, 1–100 karakter → `name`/`name_required`.
3. Nama unik dalam tahun ajaran yang sama (kecuali dirinya) → `name`/`wave_name_taken`.
4. `opens_on` tanggal `Y-m-d` valid → `opens_on`/`date_invalid`; `closes_on` → `closes_on`/`date_invalid`.
5. `closes_on >= opens_on` (sama hari diterima) → `closes_on`/`closes_before_opens`.
6. `quota`: `null`/`""` → NULL; selain itu bilangan bulat ≥ 1 (int atau string digit) → `quota`/`quota_invalid`.
7. Rentang tanggal tidak beririsan (batas inklusif) dengan gelombang lain di tahun ajaran yang sama, terbuka maupun tertutup → `opens_on`/`wave_dates_overlap`. Query memakai `whereDate`.

Output: model tersimpan. `is_open` default `true`. Dibungkus `DB::transaction`.

### `App\Actions\Psb\SaveAdmissionTrack::handle(array $data, ?AdmissionTrack $track = null): AdmissionTrack`

Input: `code`, `name`, `scholarship_id?`, `terms?`, `is_active?`.

1. `code` di-trim dan huruf besar, pola `/^[A-Z0-9_]{1,30}$/` → `code`/`code_format`.
2. Kode unik (kecuali dirinya) → `code`/`track_code_taken`.
3. `name` di-trim, 1–255 → `name`/`name_required`.
4. `scholarship_id` kosong → NULL; harus ada → `scholarship_id`/`scholarship_not_found`; harus aktif, kecuali sama dengan nilai yang sudah tersimpan pada jalur ini → `scholarship_id`/`scholarship_inactive`.
5. `terms` di-trim, kosong → NULL.
6. `is_active` default `true`.

Tidak membuat atau mengubah `fee_overrides` apa pun.

### `App\Actions\Psb\SaveAdmissionRequirement::handle(array $data, ?AdmissionRequirement $requirement = null): AdmissionRequirement`

Input: `unit_id?`, `document_type_id`, `name`, `is_required?`, `sort_order?`. Cakupan dibaca dari user login (`Auth::user()?->scopedUnitIds()`); tanpa login = tanpa cakupan.

1. Bila edit: record yang `unit_id`-nya di luar cakupan → `unit_id`/`unit_not_found`; record semua jenjang oleh user bercakupan → `unit_id`/`all_units_requires_unscoped`.
2. `unit_id` kosong → NULL. Bila NULL dan user bercakupan → `unit_id`/`all_units_requires_unscoped`. Bila terisi: harus ada dan termasuk `Unit::query()->accessible()` → `unit_id`/`unit_not_found`.
3. `document_type_id` harus ada → `document_type_id`/`document_type_not_found`; `owner` harus `santri` → `document_type_not_for_student`; harus aktif kecuali sama dengan nilai tersimpan → `document_type_inactive`.
4. `name` di-trim, 1–255 → `name`/`name_required`.
5. `sort_order` kosong → 0; bilangan bulat 0–255 → `sort_order`/`sort_order_invalid`.
6. Bentrok jenis dokumen (kecuali dirinya): ada syarat dengan `document_type_id` sama dan (`unit_id` sama, atau salah satu NULL) → `document_type_id`/`requirement_exists`.
7. `is_required` default `true`.

Hapus syarat memakai `DeleteAction` bawaan Filament (data induk non-keuangan, belum dirujuk apa pun). F010 wajib menyembunyikan hapus untuk syarat yang sudah punya `applicant_documents`.

### Perubahan `App\Actions\Keuangan\SaveFeeRate`

Setelah validasi yang ada, bila `admission_wave_id` terisi:
- gelombang harus ada → `admission_wave_id`/`wave_not_found`;
- jenis biaya harus berfrekuensi `saat_daftar` atau `saat_diterima` → `admission_wave_id`/`wave_rate_for_non_psb_fee`.

Pesan di `pondok.keuangan.errors.*`. Hapus kalimat PHPDoc "belum divalidasi keberadaannya sampai F009".

## UI (Filament / Blade)

Rujukan design guide: §0 checklist, §3 (Heroicon Outlined, kapital hanya di awal kalimat → `$hasTitleCaseModelLabel = false`), §5 (tanggal `->date('j M Y')`, kosong `-`), §6 (halaman `Manage…` modal, `defaultSort`, eager-load, filter jenjang `accessible()`), §7 (empty state), §8 (hapus dengan konfirmasi, label kata kerja + objek). Status `is_open`/`is_active`/`is_required` bukan status domain di tabel §4, jadi tampil sebagai `IconColumn` boolean atau teks, tidak membuat warna badge baru.

Grup navigasi baru `pondok.navigation.psb` = "PSB". Semua resource memakai `BelongsToModule` dengan `getModule(): 'psb'` dan `ShowsActionErrorsOnForm`.

### `AdmissionWaveResource` — "Gelombang PSB" (`/admin/admission-waves`, sort 1, ikon `Heroicon::OutlinedCalendarDays`)

- Form (`columns(2)`): `academic_year_id` (Select, urut `starts_on` desc; default tahun ajaran pertama yang `starts_on`-nya setelah tahun aktif, kosong bila tidak ada), `name`, `opens_on`, `closes_on` (DatePicker), `quota` (TextInput integer, min 1, helper "Kosongkan bila tanpa batas"), `is_open` (Toggle, default true, helper bahwa pendaftaran hanya diterima bila aktif dan dalam rentang tanggal).
- Tabel: `name`, `academicYear.name`, `opens_on`, `closes_on` (`j M Y`), `quota` (placeholder `pondok.admission_wave.unlimited` = "Tanpa batas"), `is_open` (IconColumn). Default sort: `starts_on` tahun ajaran desc, lalu `opens_on` asc. Filter: tahun ajaran (SelectFilter), `is_open` (TernaryFilter). Empty state `pondok.admission_wave.empty`.
- Aksi: `CreateAction`/`EditAction` memanggil `SaveAdmissionWave` lewat `saveShowingFormErrors`. **Tidak ada** hapus maupun bulk delete (gelombang ditutup lewat `is_open`).

### `AdmissionTrackResource` — "Jalur pendaftaran" (`/admin/admission-tracks`, sort 2, ikon `Heroicon::OutlinedArrowsRightLeft`)

- Form: `code`, `name`, `scholarship_id` (Select, placeholder "Tanpa beasiswa", opsi program aktif + program yang sedang terpasang, label "Nama (Potongan 50%)" via `defaultValueLabel()`, helper "Keringanan dari program ini dibuat otomatis saat pendaftar jalur ini diterima."), `terms` (Textarea, `columnSpanFull`), `is_active` (Toggle default true).
- Tabel: `code`, `name`, `scholarship.name` (placeholder "Tanpa beasiswa", deskripsi `defaultValueLabel()`), `is_active`. Default sort `code`. Filter `is_active`. Empty state.
- Aksi: create/edit lewat `SaveAdmissionTrack`. Tidak ada hapus maupun bulk delete (jalur dinonaktifkan).

### `AdmissionRequirementResource` — "Syarat berkas" (`/admin/admission-requirements`, sort 3, ikon `Heroicon::OutlinedClipboardDocumentList`)

- `getEloquentQuery()`: `unit_id IS NULL OR unit_id IN (Unit::accessible())`.
- Form: `unit_id` (Select opsi `Unit::accessible()`, placeholder "Semua jenjang"; `required()` bila user bercakupan), `document_type_id` (Select opsi jenis dokumen aktif ber-owner `santri` + nilai tersimpan, `live()`, `afterStateUpdated` mengisi `name` dengan nama jenis dokumen bila `name` masih kosong), `name`, `is_required` (Toggle default true), `sort_order` (integer 0–255, default 0).
- Tabel: `unit.name` (placeholder "Semua jenjang"), `name`, `documentType.name`, `is_required` (teks "Wajib"/"Opsional" dari `pondok.admission_requirement.required_labels`), `sort_order`. Default sort: `sort_order`, lalu `id`. Filter jenjang (`accessible()`), jenis dokumen. Empty state.
- Aksi: `EditAction` (lewat `SaveAdmissionRequirement`), `DeleteAction` dengan konfirmasi (deskripsi akibat). Keduanya **tersembunyi** untuk syarat semua jenjang bila user bercakupan. Tidak ada bulk delete.

### Perubahan `FeeRateResource`

- Form: `Select::make('admission_wave_id')` label "Gelombang PSB", placeholder "Semua gelombang", opsi gelombang urut tahun ajaran desc lalu `opens_on`, label "Gelombang 1 — 2027/2028". Tampil hanya bila `Setting::moduleEnabled('psb')` **dan** jenis biaya terpilih berfrekuensi `saat_daftar`/`saat_diterima`.
- `EditAction`: nilai lama `admission_wave_id` dipertahankan **hanya bila modul PSB mati** (`['admission_wave_id' => Setting::moduleEnabled('psb') ? null : $record->admission_wave_id, ...$data]`). Bila modul aktif, nilai dari form (tersembunyi untuk biaya non-PSB → NULL).
- Tabel: kolom `admissionWave.name` label "Gelombang PSB", placeholder "Semua gelombang"; eager-load `admissionWave`.

### Penjaga hapus

- `UnitResource`: `withExists(['feeRates', 'admissionRequirements'])`, `DeleteAction` tersembunyi bila salah satu ada.
- `AcademicYearResource`: `withExists(['feeRates', 'admissionWaves'])`, `DeleteAction` tersembunyi bila aktif atau salah satu ada.

### Teks `lang/id/pondok.php` (kunci baru)

`navigation.psb`; `admission_wave.{label, plural, name, academic_year, opens_on, closes_on, quota, quota_helper, unlimited, is_open, is_open_helper, empty}`; `admission_track.{label, plural, code, code_helper, name, scholarship, no_scholarship, scholarship_helper, terms, is_active, empty}`; `admission_requirement.{label, plural, unit, all_units, document_type, name, is_required, required_labels.{1,0}, sort_order, empty, delete_description}`; `fee_rate.{admission_wave, all_waves}`; `psb.errors.{academic_year_not_found, name_required, wave_name_taken, date_invalid, closes_before_opens, quota_invalid, wave_dates_overlap, code_format, track_code_taken, scholarship_not_found, scholarship_inactive, unit_not_found, all_units_requires_unscoped, document_type_not_found, document_type_not_for_student, document_type_inactive, sort_order_invalid, requirement_exists}`; `keuangan.errors.{wave_not_found, wave_rate_for_non_psb_fee}`. Pesan error berupa kalimat yang bisa ditindaklanjuti tanpa placeholder (design guide §9).

## Permission & cakupan

### Permission baru

`RolePermissionSeeder::RESOURCE_MODELS` ditambah `AdmissionWave`, `AdmissionTrack`, `AdmissionRequirement` (masing-masing 6 aksi standar, tanpa aksi tambahan). Policy dibuat dengan `php artisan shield:generate` untuk ketiga resource (format `view-any:admission-wave`, dst.). Tidak ada permission `DocumentType`.

### Pembagian ke peran

- `admin-psb`: `view-dashboard`, `view-psb-metrics`, `AdmissionWave` dan `AdmissionTrack` tanpa hapus (`viewAny`, `view`, `create`, `update`), `AdmissionRequirement` lengkap 6 aksi.
- `super-admin`: semua (otomatis).
- Peran lain tidak berubah. Bendahara tidak mendapat permission PSB; ia tetap bisa memilih gelombang di form tarif karena opsi Select tidak dicek permission.

### Cakupan jenjang

- Gelombang dan jalur lintas jenjang: tanpa cakupan.
- Syarat berkas: tidak memakai global scope (`HasUnitScope`) karena F010 memanggil `forUnit()` dari form publik tanpa login. Cakupan diterapkan di `AdmissionRequirementResource::getEloquentQuery()` dan di `SaveAdmissionRequirement` (langkah 1–2). User bercakupan melihat syarat semua jenjang (read-only) dan syarat jenjangnya.

### Saklar modul

`modules.psb.enabled`: `BelongsToModule` pada ketiga resource; field gelombang di form tarif ikut tersembunyi. `SettingSeeder::ENABLED_BY_DEFAULT = ['akademik', 'asrama', 'psb', 'keuangan']` (instalasi baru; `firstOrCreate` tidak menimpa nilai yang sudah ada).

## Kriteria penerimaan (bisa dites)

Fixture bersama: `UnitSeeder` (SD, SLTP, SLTA); tahun ajaran `Y2026` (2026-07-01..2027-06-30, aktif) dan `Y2027` (2027-07-01..2028-06-30); `DocumentTypeSeeder`; program beasiswa aktif `Beasiswa Tahfidz` (persen 50) dan nonaktif `Beasiswa Lama`. User `admin-psb` tanpa cakupan `P`, kecuali disebut lain.

Skema & migrasi
- AC-1: Setelah semua migration, `Schema::hasColumns` benar untuk `document_types` (code, name, owner, is_required, has_expiry, is_confidential, is_active), `admission_waves` (academic_year_id, name, opens_on, closes_on, quota, is_open), `admission_tracks` (code, name, scholarship_id, terms, is_active), `admission_requirements` (unit_id, document_type_id, name, is_required, sort_order, unit_key).
- AC-2: Pelestarian `fee_rates` setelah migration FK: `Schema::getColumns('fee_rates')` memuat `cohort_key`, `dormitory_key`, `wave_key` dengan `generation` tidak null (generated); `Schema::getIndexes('fee_rates')` memuat index bernama `fee_rates_combination_unique` yang `unique = true` dengan kolom tepat [`fee_type_id`, `effective_from_year_id`, `unit_id`, `cohort_key`, `dormitory_key`, `wave_key`] dan index dengan kolom [`fee_type_id`, `unit_id`]; `Schema::getForeignKeys('fee_rates')` memuat FK `admission_wave_id` → `admission_waves`.`id` dengan `on_delete` `restrict` (case-insensitive) **dan** kelima FK lama (`fee_type_id`, `effective_from_year_id`, `unit_id`, `cohort_id`, `dormitory_id`) tetap ada dengan `on_delete` `restrict`.
- AC-3: Perilaku `fee_rates` setelah migrasi: insert lewat factory dengan gelombang `W` → `wave_key` = `W.id`, tanpa gelombang → `wave_key` 0, `cohort_key`/`dormitory_key` terisi sesuai; dua tarif dengan kombinasi sama dan gelombang `W` → `UniqueConstraintViolationException`; dua tarif default tanpa gelombang → `UniqueConstraintViolationException`; kombinasi sama dengan gelombang `W1` dan `W2` → keduanya tersimpan. Insert dengan `admission_wave_id` 999999 (tidak ada) → `QueryException`. Menghapus gelombang yang dirujuk tarif → `QueryException`, gelombang tetap ada; gelombang tanpa tarif bisa dihapus lewat `delete()`.
- AC-4: Constraint lain: `admission_tracks.code` ganda → `UniqueConstraintViolationException`; `admission_waves` (`academic_year_id`, `name`) ganda → `UniqueConstraintViolationException`, nama sama di tahun lain berhasil; `admission_requirements` (KK, semua jenjang) dua kali → `UniqueConstraintViolationException`; (KK, SD) dua kali → `UniqueConstraintViolationException`; (KK, SD) + (KK, SLTP) berhasil; `unit_key` = 0 untuk NULL dan = id jenjang bila terisi. `QueryException` saat menghapus: tahun ajaran yang punya gelombang, program beasiswa yang dirujuk jalur, jenjang yang punya syarat, jenis dokumen yang dirujuk syarat.

Seeder
- AC-5: `DocumentTypeSeeder` membuat tepat 7 jenis: `KK` (Kartu Keluarga, santri, wajib, rahasia), `AKTA` (Akta kelahiran, santri, wajib, rahasia), `IJAZAH`, `RAPOR`, `FOTO` (Pas foto), `BPJS` (santri, `has_expiry` true), `SERTIFIKAT` (owner `prestasi`); selain yang disebut, `is_required`/`is_confidential`/`has_expiry` false dan semua `is_active` true. Dijalankan dua kali → tetap 7; nama yang sudah diubah tidak ditimpa.
- AC-6: `AdmissionTrackSeeder` membuat `REGULER`, `PRESTASI`, `TAHFIDZ`, `YATIM` (nama "Reguler", "Prestasi", "Tahfidz", "Yatim"), semua aktif, `scholarship_id` dan `terms` NULL. Dijalankan dua kali → tetap 4; jalur yang sudah diberi beasiswa atau dinonaktifkan tidak ditimpa. `DatabaseSeeder` menghasilkan 7 jenis dokumen dan 4 jalur.
- AC-7: Instalasi baru (`settings` kosong lalu `SettingSeeder`) → `Setting::moduleEnabled('psb')` true; akademik, asrama, keuangan tetap true; `modules.psb.enabled` yang sudah `false` tidak ditimpa oleh seed ulang.

Gelombang (`SaveAdmissionWave`)
- AC-8: Create valid (`Y2027`, name " Gelombang 1 ", opens "2027-01-01", closes "2027-03-31", quota "150", tanpa `is_open`) → satu baris: name "Gelombang 1", quota int 150, `is_open` true, `opens_on->format('Y-m-d')` "2027-01-01". `quota` `""` dan `null` → NULL. `closes_on` sama dengan `opens_on` diterima.
- AC-9: Gagal tanpa baris baru, dataset field → reason: tahun ajaran id 999 → `academic_year_id`/`academic_year_not_found`; name "  " atau 101 karakter → `name`/`name_required`; name sama di `Y2027` → `name`/`wave_name_taken` (nama sama di `Y2026` berhasil); opens null atau "01-01-2027" → `opens_on`/`date_invalid`; closes "2027-02-30" → `closes_on`/`date_invalid`; closes 2026-12-31 untuk opens 2027-01-01 → `closes_on`/`closes_before_opens`; quota 0, -1, "1.5", "abc" → `quota`/`quota_invalid`.
- AC-10: Irisan rentang (ada `Y2027` 2027-01-01..2027-03-31): baru 2027-03-31..2027-05-31 → `opens_on`/`wave_dates_overlap`; 2026-12-01..2027-01-01 → ditolak; 2026-12-01..2027-06-30 (melingkupi) → ditolak; 2027-04-01..2027-05-31 → berhasil; rentang sama di `Y2026` → berhasil; gelombang lama `is_open` false tetap dihitung. Edit gelombang itu sendiri tanpa ubah tanggal → berhasil; edit gelombang kedua sehingga beririsan → `wave_dates_overlap`, data tidak berubah.
- AC-11: Gelombang `Y2027` 2027-01-01..2027-03-31 `is_open` true dan gelombang lain 2027-01-01..2027-03-31 di `Y2026` `is_open` false: `AdmissionWave::acceptingRegistrationsOn(Carbon '2027-01-01')` dan `'2027-03-31'` hanya mengembalikan yang pertama; `'2026-12-31'` dan `'2027-04-01'` kosong. `isAcceptingRegistrationsOn()` memberi hasil yang sama per gelombang. Kuota 1 tidak memengaruhi hasil.

Jalur (`SaveAdmissionTrack`)
- AC-12: Create valid code " tahfidz_2 ", name " Tahfidz Lanjutan ", `scholarship_id` `Beasiswa Tahfidz`, terms "  " → code "TAHFIDZ_2", name "Tahfidz Lanjutan", scholarship terisi, terms NULL, `is_active` true. `scholarship_id` `""` → NULL. Jumlah `fee_overrides` tetap 0 setelah create dan edit.
- AC-13: Gagal tanpa perubahan, dataset: code "", "AB-1", 31 karakter → `code`/`code_format`; code "reguler" saat `REGULER` ada → `code`/`track_code_taken`; name "  " → `name`/`name_required`; `scholarship_id` 999 → `scholarship_id`/`scholarship_not_found`; `Beasiswa Lama` (nonaktif) → `scholarship_id`/`scholarship_inactive`. Edit jalur tanpa ubah kode tidak bentrok dengan dirinya. Jalur yang sudah terhubung ke program yang kemudian dinonaktifkan tetap bisa diedit (nama berubah) selama `scholarship_id` tidak diganti; mengganti ke program nonaktif lain → `scholarship_inactive`.

Syarat berkas (`SaveAdmissionRequirement`)
- AC-14: Create valid oleh `P` (SD, KK, name " Kartu Keluarga ", sort_order "2", tanpa `is_required`) → name "Kartu Keluarga", sort_order int 2, `is_required` true. `unit_id` `""` → NULL (semua jenjang). `sort_order` kosong → 0.
- AC-15: Gagal tanpa baris baru, dataset: unit 999 → `unit_id`/`unit_not_found`; jenis dokumen 999 → `document_type_id`/`document_type_not_found`; `SERTIFIKAT` (owner prestasi) → `document_type_not_for_student`; jenis dokumen nonaktif → `document_type_inactive`; name "  " → `name`/`name_required`; sort_order -1, 256, "1.5" → `sort_order`/`sort_order_invalid`. Edit syarat yang jenis dokumennya kemudian dinonaktifkan tetap berhasil bila `document_type_id` tidak diganti.
- AC-16: Bentrok jenis dokumen: ada KK semua jenjang → KK SD ditolak `document_type_id`/`requirement_exists`; ada KK SD → KK semua jenjang ditolak, KK SLTP berhasil, AKTA SD berhasil. Edit syarat itu sendiri berhasil; edit AKTA SD menjadi KK SD → `requirement_exists`.
- AC-17: Cakupan: login `admin-psb` bercakupan SLTP: create unit SD → `unit_id`/`unit_not_found`; unit NULL → `unit_id`/`all_units_requires_unscoped`; SLTP berhasil. Edit syarat semua jenjang → `all_units_requires_unscoped`; edit syarat SD → `unit_not_found`. Tanpa login (unscoped) create semua jenjang berhasil.
- AC-18: Syarat: KK semua jenjang (sort 2), AKTA SLTP (sort 1), RAPOR SD (sort 0), FOTO SLTP (sort 1, id lebih besar dari AKTA): `AdmissionRequirement::forUnit($sltp->id)->pluck('name')` = [AKTA, FOTO, KK] berurutan; tidak memuat RAPOR. Hasil sama tanpa login dan saat login sebagai user bercakupan SD.

Integrasi tarif
- AC-19: `SaveFeeRate` dengan jenis biaya `saat_daftar` + gelombang `W` → tersimpan dengan `admission_wave_id` `W`; jenis `saat_diterima` + `W` → tersimpan; `admission_wave_id` 999 → `admission_wave_id`/`wave_not_found`; jenis `bulanan` + `W` → `admission_wave_id`/`wave_rate_for_non_psb_fee`; kombinasi sama dengan `W` dua kali → `duplicate_rate` (tetap). Tanpa gelombang perilaku F004 tidak berubah.
- AC-20: Test resolver F004 AC-13 (gelombang cocok menang, fallback semua gelombang, tarif gelombang tidak dipakai tanpa gelombang, tarif angkatan tanpa gelombang menang atas tarif jenjang bergelombang) lulus dengan gelombang nyata dari factory.
- AC-21: `ManageFeeRates` sebagai bendahara (modul PSB aktif): saat `fee_type_id` jenis `saat_daftar`, field `admission_wave_id` tampil; saat jenis `bulanan`, tersembunyi; create dengan gelombang `W` → tarif tersimpan dengan `W`; tabel menampilkan nama gelombang dan "Semua gelombang" untuk tarif tanpa gelombang. Edit tarif bergelombang dan pilih "Semua gelombang" → `admission_wave_id` NULL. Modul PSB mati: field tersembunyi untuk jenis `saat_daftar`, dan edit nominal tarif bergelombang mempertahankan `admission_wave_id`.

Akses, saklar, cakupan (HTTP/Livewire)
- AC-22: `RolePermissionSeeder` membuat 18 permission baru (`view-any|view|create|update|delete|delete-any` × `admission-wave`, `admission-track`, `admission-requirement`); `admin-psb` mendapat persis: `view-dashboard`, `view-psb-metrics`, `view-any:admission-wave`, `view:admission-wave`, `create:admission-wave`, `update:admission-wave`, `view-any:admission-track`, `view:admission-track`, `create:admission-track`, `update:admission-track`, dan keenam permission `admission-requirement`. Dataset peran lain tidak berubah; test "creates every permission that a generated policy checks" tetap lulus dengan tiga policy baru.
- AC-23: `admin-psb` dan super-admin: `GET /admin/admission-waves`, `/admin/admission-tracks`, `/admin/admission-requirements` → 200. bendahara, admin-sekolah, kesantrian, musyrif, admin-konten, ustadz-tahfidz, petugas-pos → 403 pada ketiganya.
- AC-24: `modules.psb.enabled = false` → super-admin dan `admin-psb` mendapat 403 di ketiga URL; `/admin` (super-admin) tidak memuat `__('pondok.navigation.psb')` maupun label jamak ketiga resource. Saat aktif, `/admin` untuk `admin-psb` memuat `__('pondok.navigation.psb')` dan `__('pondok.navigation.psb')` bukan kunci mentah.
- AC-25: `admin-psb` bercakupan SLTP di `ManageAdmissionRequirements`: melihat syarat semua jenjang dan SLTP, tidak melihat syarat SD (`assertCanSeeTableRecords`/`assertCanNotSeeTableRecords`); aksi `edit` dan `delete` tersembunyi untuk syarat semua jenjang, tampil untuk syarat SLTP; opsi `unit_id` di form create hanya SLTP. `admin-psb` tanpa cakupan melihat semuanya.

UI aksi (Livewire)
- AC-26: `ManageAdmissionWaves` sebagai `admin-psb`: create lewat modal → tersimpan; create yang beririsan → error `wave_dates_overlap` tampil di field `opens_on`, jumlah baris tetap; edit `is_open` false → tersimpan; aksi `delete` tidak ada di tabel dan tidak ada bulk delete; tabel kosong menampilkan `pondok.admission_wave.empty`; kuota NULL tampil "Tanpa batas"; tanggal tampil "1 Jan 2027". Form create terisi default `academic_year_id` = `Y2027` (tahun setelah tahun aktif `Y2026`).
- AC-27: `ManageAdmissionTracks`: create → tersimpan; kode ganda → error di field `code`, jumlah baris tetap; edit `scholarship_id` → tersimpan; opsi beasiswa tidak memuat `Beasiswa Lama` kecuali sedang terpasang pada record yang diedit; tidak ada `delete` maupun bulk delete; tabel kosong menampilkan `pondok.admission_track.empty`.
- AC-28: `ManageAdmissionRequirements`: create → tersimpan; memilih jenis dokumen `AKTA` saat `name` kosong mengisi `name` "Akta kelahiran"; bentrok → error di field `document_type_id`, jumlah baris tetap; opsi jenis dokumen tidak memuat `SERTIFIKAT` maupun jenis nonaktif; aksi `delete` (dengan konfirmasi) menghapus baris; tidak ada bulk delete; kolom jenjang NULL tampil "Semua jenjang", `is_required` tampil "Wajib"/"Opsional".
- AC-29: `ManageUnits` (super-admin): `delete` tersembunyi untuk jenjang yang punya syarat berkas, tampil untuk jenjang tanpa tarif dan tanpa syarat. `ManageAcademicYears`: `delete` tersembunyi untuk tahun ajaran nonaktif yang punya gelombang, tampil untuk tahun nonaktif tanpa tarif dan tanpa gelombang.

Activitylog & teks
- AC-30: Login `P`, create gelombang lewat `SaveAdmissionWave` → tepat satu activity `log_name = 'psb'`, `event = 'created'`, subject gelombang itu, `causer_id = P`; edit `quota` 150 → 200 → activity `updated` dengan `old.quota = 150`, `attributes.quota = 200`; simpan ulang tanpa perubahan (tanggal sama) → tidak ada activity baru. Create jalur lalu ganti `scholarship_id` → activity `created` dan `updated` (`log_name = 'psb'`) dengan `attributes.scholarship_id` baru.
- AC-31: Setiap reason di `pondok.psb.errors.*` (18 kunci di bagian UI) dan `pondok.keuangan.errors.wave_not_found`/`wave_rate_for_non_psb_fee`, serta `pondok.navigation.psb`, `pondok.admission_wave.empty`, `pondok.admission_track.empty`, `pondok.admission_requirement.empty`, `pondok.fee_rate.all_waves`, terjemahannya bukan kunci mentah (dataset).

## Rencana test

Konvensi nama: `it('AC-n: ...')` seperti F004–F008. Helper baru di `tests/Pest.php` bagian "Helper PSB (F009)": `psbUser(string $role, array $unitCodes = [])`, `psbFixture()` (tahun ajaran, jenis dokumen, beasiswa).

| AC | File | Nama test (usulan) |
|---|---|---|
| 1, 4 | `tests/Feature/Database/PsbConstraintsTest.php` | `creates the psb master tables`, `rejects duplicate track codes`, `rejects duplicate wave names per academic year`, `rejects duplicate requirements through unit_key`, `restricts deleting referenced psb master data` (dataset) |
| 2, 3 | `tests/Feature/Database/FeeRateWaveForeignKeyTest.php` | `keeps generated keys after adding the wave foreign key`, `keeps the combination unique index`, `keeps every fee rate foreign key restrictive`, `fills wave_key from a real wave`, `still rejects duplicate wave rates`, `rejects unknown waves`, `restricts deleting a wave with rates` |
| 5, 6, 7 | `tests/Feature/Database/Seeders/PsbSeedersTest.php` | `seeds the document types`, `seeds the default admission tracks`, `does not overwrite edited seed data`, `seeds psb master data from DatabaseSeeder`, `enables psb on a fresh install` |
| 8–11 | `tests/Feature/Actions/Psb/SaveAdmissionWaveTest.php` | `creates a wave`, `rejects invalid waves` (dataset), `rejects overlapping waves` (dataset), `finds waves accepting registrations on a date` |
| 12, 13 | `tests/Feature/Actions/Psb/SaveAdmissionTrackTest.php` | `creates a track`, `rejects invalid tracks` (dataset), `keeps an inactive scholarship that is already linked` |
| 14–18 | `tests/Feature/Actions/Psb/SaveAdmissionRequirementTest.php` | `creates a requirement`, `rejects invalid requirements` (dataset), `rejects conflicting requirements`, `respects unit scope`, `lists requirements for a unit` |
| 19 | `tests/Feature/Actions/Keuangan/SaveFeeRateTest.php` (tambah) | `AC-19 (F009): validates the admission wave` (dataset) |
| 20 | `tests/Feature/Actions/Keuangan/ResolveFeeRateTest.php` (ubah) | tiga test `AC-13` yang ada, gelombang fiktif diganti factory |
| 21 | `tests/Feature/Filament/Resources/FeeRates/FeeRateResourceTest.php` (tambah) | `shows the wave field for psb fees only`, `saves and clears the wave`, `hides the wave field and keeps the value when psb is disabled` |
| 22 | `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (ubah) | dataset `admin-psb` di `grants each role exactly its permissions`, `AC-22: creates the psb master permissions` |
| 23–25, 31 | `tests/Feature/Filament/PsbAccessTest.php` | `lets admin-psb manage psb master data`, `forbids psb master pages to other roles` (dataset), `returns 403 and hides the menu when psb is disabled`, `shows the psb menu when enabled`, `applies unit scope to requirements`, `translates every psb text key` (dataset) |
| 26 | `tests/Feature/Filament/Resources/AdmissionWaves/AdmissionWaveResourceTest.php` | sesuai AC-26 |
| 27 | `tests/Feature/Filament/Resources/AdmissionTracks/AdmissionTrackResourceTest.php` | sesuai AC-27 |
| 28 | `tests/Feature/Filament/Resources/AdmissionRequirements/AdmissionRequirementResourceTest.php` | sesuai AC-28 |
| 29 | `tests/Feature/Filament/Resources/PsbReferencesTest.php` | `hides unit delete when it has requirements`, `hides academic year delete when it has waves` |
| 30 | `tests/Feature/Models/PsbActivityLogTest.php` | `logs wave changes`, `logs track scholarship changes`, `skips empty saves` |

**Penyesuaian test lama (dilakukan red-tester di mode RED, bukan green-coder).** FK baru membuat nilai `admission_wave_id` fiktif gagal, dan aturan baru menolak gelombang untuk biaya non-PSB:
- `tests/Feature/Database/KeuanganConstraintsTest.php` AC-22 (`includes the wave key in the unique rate`, `fills generated keys ...`): ganti 7/8 dengan `AdmissionWave::factory()`, `wave_key` dibandingkan dengan id gelombang.
- `tests/Feature/Actions/Keuangan/ResolveFeeRateTest.php` AC-13 (3 test): gelombang nyata dari factory (resolver tidak memeriksa frekuensi, jadi tipe `ASRAMA` boleh tetap).
- `tests/Feature/Actions/Keuangan/SaveFeeRateTest.php` AC-21 (dataset `wave` dan `allows rates that differ in one dimension`): gelombang nyata dan jenis biaya `saat_daftar`.
- `tests/Feature/Database/Seeders/RolePermissionSeederTest.php`: dataset `admin-psb`.
Perubahan ini harus mempertahankan maksud test aslinya; tidak boleh melonggarkan assert.

## Urutan implementasi

1. Migration + model + factory `document_types`; `DocumentTypeSeeder`.
2. Migration + model + factory `admission_waves`, `admission_tracks`, `admission_requirements` (dengan `unit_key`); relasi balik di `AcademicYear`, `Scholarship`, `Unit`, `DocumentType`.
3. Migration FK `fee_rates.admission_wave_id`; relasi `FeeRate::admissionWave()`; penyesuaian test lama F004; pastikan AC-2/AC-3 hijau di SQLite (dan MySQL bila tersedia).
4. `AdmissionTrackSeeder`, `DatabaseSeeder`, `SettingSeeder::ENABLED_BY_DEFAULT`.
5. `SaveAdmissionWave` + scope `acceptingRegistrationsOn`.
6. `SaveAdmissionTrack`.
7. `SaveAdmissionRequirement` + scope `forUnit`.
8. Validasi gelombang di `SaveFeeRate`.
9. `RolePermissionSeeder` (RESOURCE_MODELS, admin-psb) + `shield:generate` untuk tiga policy.
10. Teks `lang/id/pondok.php`.
11. Tiga resource Filament + grup navigasi PSB.
12. Field/kolom gelombang di `FeeRateResource`; penjaga hapus `UnitResource`/`AcademicYearResource`.
13. Activitylog pada `AdmissionWave`/`AdmissionTrack`.
14. Pint, test terkait, checklist design guide §0 (cek visual desktop dan 360px atau laporkan belum dicek).

## Risiko & pertanyaan terbuka

Setiap pertanyaan punya default yang dipakai bila user tidak memutuskan lain.

1. **`document_types` dibuat di F009 (opsi a).** Default: ya, tabel lengkap ERD tanpa UI/permission. F013 tinggal menambah resource, `documents`, dan aturan kerahasiaan. *Perlu catatan di entri F013.*
2. **Jenis dokumen bawaan.** Default: 7 jenis (6 contoh ERD + `FOTO` "Pas foto" karena lazim untuk PSB). KK dan AKTA `is_required` dan `is_confidential` true. F013 bisa menyesuaikan lewat UI-nya; seeder tidak menimpa.
3. **Syarat berkas bawaan.** Default: tidak di-seed; tiap sekolah mengatur sendiri.
4. **Gelombang dan jalur tidak dihapus.** Default: ditutup (`is_open`) atau dinonaktifkan (`is_active`); `admin-psb` tidak mendapat permission hapus untuk keduanya. Syarat berkas boleh dihapus selama belum dirujuk (F010 menambah penjaga).
5. **Irisan tanggal gelombang.** Default: dilarang dalam satu tahun ajaran, termasuk gelombang tertutup, agar F010 selalu menemukan paling banyak satu gelombang yang menerima pendaftaran pada satu tanggal. Antartahun ajaran boleh beririsan (mis. gelombang inden tahun berikutnya) — F010 harus memutuskan pilihan bila lebih dari satu gelombang menerima pada tanggal yang sama.
6. **Unique (`academic_year_id`, `name`) di gelombang** tidak tertulis di ERD. Default: ditambahkan (tidak ada kolom nullable, risiko nol). Usulkan sinkronisasi tabel constraint ERD.
7. **Syarat semua jenjang vs per jenjang untuk jenis dokumen sama.** Default: tidak boleh berdampingan (Action) + unique generated `unit_key` (DB). Tambahan di luar tabel constraint ERD; usulkan sinkronisasi.
8. **Gelombang hanya untuk biaya PSB.** Default: `SaveFeeRate` menolak gelombang pada jenis biaya selain `saat_daftar`/`saat_diterima`. Konsekuensi: test lama F004 yang memakai tipe `ASRAMA` + gelombang di `SaveFeeRateTest` disesuaikan. Tidak ada validasi bahwa tahun berlaku tarif ≤ tahun ajaran gelombang (tarif yang tidak pernah terpakai tidak merusak data).
9. **Activitylog PSB.** Default: `AdmissionWave` dan `AdmissionTrack` dicatat (log `psb`) karena memengaruhi tarif dan beasiswa; `AdmissionRequirement` dan `DocumentType` tidak.
10. **Modul PSB aktif bawaan.** Default: ya, mengikuti pola keuangan yang diaktifkan saat panel pertamanya selesai. Pendaftaran publik baru ada di F010, jadi tidak ada efek samping ke pengunjung.
11. **Beasiswa jalur tidak punya jenis biaya.** `scholarships` tidak menyimpan jenis biaya, sedangkan `fee_overrides` per jenis biaya. F011 harus memutuskan jenis biaya mana yang mendapat keringanan otomatis (mis. semua `saat_diterima`, atau konfigurasi baru). Di luar F009; dicatat agar F011 tidak terkejut.
12. **Perombakan tabel SQLite.** Risiko utama fitur ini; dimitigasi AC-2/AC-3. `down()` migration FK tidak diuji otomatis; green-coder menjalankan `migrate:rollback --step=1` lalu `migrate` manual sekali di SQLite dan mencatat hasilnya di laporan.
13. **Pemilik jenis dokumen di syarat PSB.** Default: hanya owner `santri`, karena berkas valid disalin ke dokumen santri (F012).

## Riwayat revisi

- DRAFT 1 (product-manager): rencana awal. Jumlah AC: 31.

## Review #1 — 2026-10-15

Verdict: APPROVED

Semua `acceptance` di `feature_list.json` (menu hilang dan 403 saat modul mati) tercakup AC-23/24. PRD 3.4 butir 1 (gelombang, jalur, syarat berkas per jenjang) dan PRD 3.5 (tarif per gelombang) tercakup. Skema sesuai ERD "Penerimaan Santri Baru" dan "Dokumen". Tambahan unique (`academic_year_id`, `name`) dan (`document_type_id`, `unit_key`) di luar ERD sudah diterima orkestrator. Tidak ada temuan blocker atau major. Verifikasi independen: `SQLiteGrammar::compileAlter` (vendor) mengecualikan kolom `storedAs` dari `insert ... select` saat rebuild dan `BlueprintState` membaca `generation`, jadi rebuild `fee_rates` secara teori aman; tidak ada tabel lain yang mereferensikan `fee_rates`, jadi `PRAGMA foreign_keys` yang no-op di dalam transaksi migration tidak berbahaya. AC-2/AC-3 tetap wajib sebagai pembuktian.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Model data `admission_requirements` (unique `document_type_id` + `unit_key`) | Satu jenis dokumen hanya boleh punya satu syarat per jenjang. Kasus nyata seperti dua syarat bertipe RAPOR (semester 1 dan 2) atau foto 3x4 dan 4x6 tidak bisa dimodelkan, padahal ERD tidak melarangnya. Sudah diterima orkestrator, tetapi F012 (salin berkas ke `documents`) bergantung pada pemetaan satu jenis ke satu syarat. | Catat konsekuensi ini di ERD saat green-coder menyinkronkan constraint, dan di catatan F010/F012 supaya pelonggaran nanti dipahami sebagai perubahan skema. |
| 2 | minor | Migrasi FK `fee_rates` (AC-2/AC-3) | Cabang cadangan "buat ulang tabel manual" untuk SQLite hanya berlaku bila test merah. Pengujian MySQL/Postgres tidak punya AC (hanya "bila tersedia"). `down()` tidak diuji otomatis. | Pertahankan langkah manual `migrate:rollback --step=1` lalu `migrate`, dan tulis hasilnya di laporan green-coder. Jangan klaim MySQL/Postgres terverifikasi bila tidak dijalankan. Pastikan AC-3 (hapus gelombang yang dirujuk tarif) berjalan dengan `PRAGMA foreign_keys` aktif di test (konfigurasi SQLite di `config/database.php` sudah mengaktifkan; verifikasi sekali). |
| 3 | minor | Perubahan test lama F004 | Rencana sudah benar: gelombang fiktif 7/8 di `KeuanganConstraintsTest` (baris 42-61) dan `SaveFeeRateTest` (baris 60, 82) diganti factory, dan dataset `wave` memakai jenis `saat_daftar`. Maksud tidak melemah selama assert `wave_key` dibandingkan id nyata dan unique tetap diuji. Helper `keuanganRate(... ?int $waveId)` di `tests/Pest.php` tidak perlu berubah. `tests/Pest.php` sedang diubah paralel oleh F008, jadi helper "PSB (F009)" berisiko bentrok. | Tambahkan helper PSB di bagian terpisah file `tests/Pest.php` (append di akhir) dan red-tester memeriksa `git diff` sebelum menyunting. Gunakan `AdmissionWave::factory()` di ResolveFeeRateTest tanpa mengubah urutan assert AC-13. |
| 4 | minor | UI form tarif (AC-21) | Logika hidden field sudah konsisten: field tersembunyi tidak ikut `$data`, sehingga `['admission_wave_id' => modul psb aktif ? null : $record->admission_wave_id, ...$data]` benar. Satu celah kecil: bila jenis biaya sebuah tarif bergelombang kemudian diubah frekuensinya, edit tarif gagal dengan `wave_rate_for_non_psb_fee`. | Tidak perlu AC baru; cukup pastikan pesan error tampil di field lewat `ShowsActionErrorsOnForm` (sudah ada di pola F004). |
| 5 | minor | Dashboard dan saklar (CLAUDE.md "Dashboard tetap render") | AC-24 hanya menguji `/admin` untuk super-admin saat modul mati. `admin-psb` punya `view-dashboard` dan `view-psb-metrics`; belum ada AC bahwa `/admin` untuk `admin-psb` tetap 200 saat `modules.psb.enabled = false`. | Tambahkan satu assert di test `returns 403 and hides the menu when psb is disabled`: `/admin` sebagai `admin-psb` tetap 200 tanpa menu PSB. |
| 6 | minor | AC-24 | Assert "tidak memuat `__('pondok.navigation.psb')`" memakai teks "PSB" yang pendek dan bisa muncul di tempat lain (mis. kolom/teks dashboard), sehingga berisiko false negative. | Assert pada label jamak resource ("Gelombang PSB" dan sejenisnya) dan `assertDontSee` link `/admin/admission-waves`; tetap boleh memakai teks grup navigasi sebagai tambahan. |
| 7 | minor | `SettingSeeder::ENABLED_BY_DEFAULT` | Instalasi yang sudah ada (termasuk DB dev) menyimpan `modules.psb.enabled = false`; `firstOrCreate` tidak membaliknya, jadi menu PSB tidak muncul sampai diaktifkan manual. Perilaku ini benar tetapi mudah mengejutkan. | Sebutkan di laporan akhir bahwa DB dev perlu `Setting::set('modules.psb.enabled', true)` atau lewat halaman pengaturan. |
| 8 | minor | Action `SaveAdmissionWave` langkah 2 | `name` berlebih 100 karakter memakai reason `name_required` sehingga pesan menyesatkan. Aturan irisan tanggal (Risiko #5) adalah kebijakan di luar PRD/ERD, tetapi terdokumentasi dan F010 sudah diberi catatan. | Gunakan pesan netral ("Nama gelombang wajib diisi, maksimal 100 karakter") agar satu kunci akurat untuk kedua kasus. |
| 9 | minor | Hapus syarat berkas (`DeleteAction` bawaan Filament) | CLAUDE.md meminta Filament hanya memanggil Action, tetapi hapus data induk non-keuangan lewat `DeleteAction` sudah menjadi pola di fitur sebelumnya dan scoping dijaga `getEloquentQuery()` plus visibilitas aksi. | Cukup; pastikan policy `delete` dicek dan test AC-25 mencakup user bercakupan yang mencoba hapus syarat semua jenjang lewat `callTableAction` (harus ditolak, bukan hanya tersembunyi). |

Kepatuhan konvensi: uang tidak terlibat; tidak ada soft delete baru; riwayat tidak relevan; FK `restrictOnDelete` konsisten; `unit_key` memakai pola generated `COALESCE`; query portabel (tanpa fitur khusus DB); tidak ada kebutuhan Redis/Node. Urutan implementasi 14 langkah kecil dan tidak ada ketergantungan maju (langkah 3 butuh langkah 2, sudah berurutan).
