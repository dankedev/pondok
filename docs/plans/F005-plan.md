# F005 keuangan-keringanan — Beasiswa & keringanan

Status rencana: APPROVED

Owner: tim-agent-fase1. Dependensi: F004 (`done`).

## Ringkasan & tujuan

Bendahara bisa mencatat **program beasiswa** beserta donaturnya (`scholarships`) dan memberi **keringanan** per santri per jenis biaya dengan masa berlaku (`fee_overrides`). Keringanan punya tiga mode: *nominal* (bayar sejumlah ini, 0 = gratis), *potongan* (dikurangi rupiah), dan *persen* (dikurangi persen). Inti fitur ini adalah dua Action murni yang dipakai generator F006 dan alur PSB F010–F012:

- `ResolveFeeOverride` memilih satu keringanan yang aktif di bulan tagihan.
- `ApplyFeeOverride` menghitung `base_amount`, `discount_amount`, dan `amount` dari tarif dasar (`ResolveFeeRate`, F004) dan keringanan itu, termasuk kasus tarif dasar belum diatur + mode nominal (pseudocode ERD).

Keringanan tidak dihapus. Keringanan diakhiri dengan mengisi `valid_until` lewat Action `EndFeeOverride`. Semua perubahan program beasiswa dan keringanan tercatat di Activitylog (log `keuangan`).

Acuan: PRD 2 (Bendahara: tarif, beasiswa), PRD 3.5 butir 3, PRD 5 butir "menerapkan ketiga mode keringanan dengan benar"; ERD "Keuangan & Tagihan" (`scholarships`, `fee_overrides`, `invoice_items.base_amount/discount_amount/amount/fee_override_id`), catatan "Beasiswa dan keringanan" dan "`scholarships` mengelompokkan program beasiswa", "Urutan penentuan nominal per jenis biaya" (pseudocode), tabel constraint (index `fee_overrides`), "Biaya PSB" (keringanan khusus lewat `applicant_id`), tabel bawaan (`activity_log` untuk perubahan override).

## Ruang lingkup

- Termasuk:
  - Migration, model, factory: `scholarships`, `fee_overrides`.
  - Action di `app/Actions/Keuangan/`: `SaveScholarship`, `SaveFeeOverride`, `EndFeeOverride`, `ResolveFeeOverride`, `ApplyFeeOverride`.
  - Activitylog (`LogsActivity`, log name `keuangan`) pada `Scholarship` dan `FeeOverride`.
  - Panel Filament: `ScholarshipResource` ("Program beasiswa") dan `FeeOverrideResource` ("Keringanan"), halaman `Manage…`, di grup navigasi "Keuangan".
  - Permission baru + policy Shield untuk `Scholarship` dan `FeeOverride`, pembagian ke `bendahara`.
  - Relasi baru di model lama: `Student::feeOverrides()`, `FeeType::overrides()`, `User::approvedFeeOverrides()`.
  - Tombol Delete di `EditUser` disembunyikan bila user itu tercatat sebagai `approved_by` keringanan (FK baru `restrictOnDelete`).
  - Teks `lang/id/pondok.php`.
- Tidak termasuk (eksplisit):
  - Tabel `applicants` dan FK `fee_overrides.applicant_id` → `applicants` (F010). F005 hanya menyediakan kolom nullable tanpa FK (pola `students.applicant_id`) dan `ResolveFeeOverride::forApplicant()`. Form keringanan pendaftar di panel belum ada (F010/F011).
  - Penerapan otomatis beasiswa jalur (`admission_tracks.scholarship_id`) saat pendaftar diterima (F011, `AcceptApplicant`), dan pengisian `student_id` pada keringanan pendaftar saat daftar ulang (F012).
  - `invoices`, `invoice_items`, generator bulanan, dan penyimpanan `fee_override_id` di item (F006). F005 hanya menyediakan hasil hitung yang siap disalin ke item.
  - Laporan total subsidi beasiswa per program/donatur (F008 `keuangan-laporan`) dan widget dashboard (F018).
  - Keringanan massal (mis. satu program untuk banyak santri sekaligus). Lihat usulan di Risiko.
  - Menghapus program beasiswa atau keringanan dari UI. Program dinonaktifkan lewat `is_active`; keringanan diakhiri lewat `EndFeeOverride` atau dikoreksi lewat Edit.
  - Persen berdesimal (mis. 12,5%). Persen disimpan bilangan bulat 0–100.
  - Global scope `HasUnitScope` pada `FeeOverride` (lihat Permission & cakupan).

## Model data

Semua tabel memakai `timestamps`. Tidak ada soft delete. Uang dan nilai keringanan `unsignedBigInteger`. Semua FK `restrictOnDelete`.

Konstanta mode bersama di `FeeOverride`: `MODE_NOMINAL = 'nominal'`, `MODE_DISCOUNT = 'potongan'`, `MODE_PERCENT = 'persen'`, `MODES = [...]`. `Scholarship` memakai konstanta yang sama.

### `scholarships` — model `App\Models\Scholarship`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| name | string, unique | "Beasiswa Tahfidz", "Yatim", "Anak Ustadz"; di-`trim` oleh Action |
| mode | enum `nominal`, `potongan`, `persen` | mode bawaan untuk keringanan baru |
| default_value | unsignedBigInteger | rupiah (nominal/potongan) atau persen 0–100 |
| sponsor | string, nullable | donatur atau yayasan |
| is_active | boolean, default true | program nonaktif tidak bisa dipilih untuk keringanan baru |

Cast `default_value` integer, `is_active` boolean. Relasi `feeOverrides()` HasMany. Scope `active()`.

### `fee_overrides` — model `App\Models\FeeOverride`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| student_id | foreignId → students, nullable, `restrictOnDelete` | santri pemilik |
| applicant_id | unsignedBigInteger, nullable, index | **tanpa FK** sampai F010 menambah tabel `applicants` (pola `students.applicant_id`); komentar migration menyebut ini |
| fee_type_id | foreignId → fee_types, `restrictOnDelete` | |
| scholarship_id | foreignId → scholarships, nullable, `restrictOnDelete` | null = keringanan non-beasiswa |
| mode | enum `nominal`, `potongan`, `persen` | |
| value | unsignedBigInteger | nominal 0 = gratis; persen 0–100 |
| reason | string(255) | wajib; tampil sebagai alasan di item Rp 0 |
| valid_from | date | |
| valid_until | date, nullable | null = sampai lulus; inklusif |
| approved_by | foreignId → users, `restrictOnDelete` | wajib; user yang menyetujui saat dibuat |

- Index (`student_id`, `fee_type_id`, `valid_from`) sesuai ERD; index `applicant_id`.
- Cast `value` integer, `valid_from` dan `valid_until` `date`.
- Relasi `student()`, `feeType()`, `scholarship()`, `approver()` (BelongsTo `User`, kolom `approved_by`).
- Scope `activeBetween(CarbonInterface $start, CarbonInterface $end)`: `valid_from <= $end` dan (`valid_until` null atau `valid_until >= $start`), perbandingan `whereDate` dengan string `Y-m-d` (portabel SQLite/MySQL/Postgres). Scope `activeOn(CarbonInterface $date)` = `activeBetween($date, $date)`.
- Method `isFree(): bool` (`mode = nominal` dan `value = 0`).
- Tidak ada check constraint di database (pemilik wajib dijaga Action), karena schema builder tidak menyediakannya secara portabel.

### Activitylog
`Scholarship` dan `FeeOverride` memakai `LogsActivity` dengan `useLogName('keuangan')`, `logOnly($this->getFillable())`, `logOnlyDirty()`, `dontSubmitEmptyLogs()` (pola `FeeRate`). Causer = user login.

### Tambahan pada model yang sudah ada
- `Student::feeOverrides()` HasMany, `FeeType::overrides()` HasMany, `User::approvedFeeOverrides()` HasMany (`approved_by`).
- Factory baru: `ScholarshipFactory` (nama unik; state `nominal(int)`, `discount(int)`, `percent(int)`, `inactive()`), `FeeOverrideFactory` (`student` aktif, `fee_type`, `approved_by` user; bawaan mode `potongan` nilai kelipatan 1000, `valid_from` awal bulan ini, `valid_until` null; state `nominal(int)`, `free()`, `discount(int)`, `percent(int)`, `between(string $from, ?string $until)`, `forApplicant(int $applicantId)` yang mengosongkan `student_id`, `forScholarship(Scholarship)`).

## Action class

Semua di `app/Actions/Keuangan/`. Kegagalan melempar `ValidationException` dengan pesan `pondok.keuangan.errors.<reason>` pada field yang disebut (pola `SaveFeeRate`). Parsing nilai uang/persen memakai aturan yang sama dengan `SaveFeeRate::amount()`: int, atau string berisi digit saja, `>= 0`.

### `SaveScholarship::handle(array $data, ?Scholarship $scholarship = null): Scholarship`
`$data`: `name`, `mode`, `default_value`, `sponsor` (nullable), `is_active`.
1. `name` di-`trim`; kosong atau lebih dari 255 karakter → `name_required` (field `name`).
2. `name` sudah dipakai program lain (selain dirinya) → `scholarship_name_taken` (field `name`). DB unique tetap pengaman terakhir.
3. `mode` di luar `MODES` → `invalid_mode` (field `mode`).
4. `default_value` bukan bilangan bulat `>= 0` → `value_invalid`; mode `persen` dan nilai > 100 → `percent_range` (field `default_value`).
5. `sponsor` di-`trim`; string kosong disimpan null.
6. Simpan. Mengubah `mode`/`default_value` program **tidak** mengubah keringanan yang sudah ada (keringanan menyimpan mode dan nilainya sendiri).

### `SaveFeeOverride::handle(array $data, User $approver, ?FeeOverride $override = null): FeeOverride`
`$data`: `student_id` (nullable), `applicant_id` (nullable), `fee_type_id`, `scholarship_id` (nullable), `mode`, `value`, `reason`, `valid_from`, `valid_until` (nullable). Tanggal diterima sebagai `CarbonInterface` atau string tepat `Y-m-d`.

Pemilik (hanya saat create; saat edit `student_id` dan `applicant_id` di `$data` diabaikan dan pemilik tetap):
1. `student_id` dan `applicant_id` sama-sama kosong → `owner_required` (field `student_id`).
2. `student_id` terisi tetapi tidak ditemukan lewat `Student::query()` (global scope cakupan jenjang user login berlaku; santri terhapus lunak tidak ditemukan) → `student_not_found` (field `student_id`).
3. Santri ditemukan tetapi `status` bukan `aktif` → `student_not_active` (field `student_id`).
4. `applicant_id` terisi tidak dicek keberadaannya di F005 (tabel belum ada; F010 menambah validasi). Keduanya boleh terisi bersamaan.

Isi:
5. `fee_type_id` tidak ada → `fee_type_not_found` (field `fee_type_id`).
6. `scholarship_id` terisi tetapi tidak ada → `scholarship_not_found`; program nonaktif → `scholarship_inactive` (field `scholarship_id`). Saat edit, program nonaktif tetap boleh bila `scholarship_id` tidak berubah.
7. `mode` di luar `MODES` → `invalid_mode` (field `mode`).
8. `value` bukan bilangan bulat `>= 0` → `value_invalid`; mode `persen` dan `value` > 100 → `percent_range` (field `value`).
9. `reason` di-`trim`; kosong atau lebih dari 255 karakter → `reason_invalid` (field `reason`).
10. `valid_from` kosong atau bukan tanggal valid `Y-m-d` → `date_invalid` (field `valid_from`); `valid_until` terisi tetapi bukan tanggal valid → `date_invalid` (field `valid_until`).
11. `valid_until` < `valid_from` → `until_before_from` (field `valid_until`).
12. **Tanpa tumpang tindih**: dalam `DB::transaction`, ada keringanan lain (selain dirinya) untuk pemilik yang sama (`student_id` sama bila pemilik santri; bila `student_id` null, `applicant_id` sama) dan `fee_type_id` sama yang rentangnya beririsan (inklusif; `valid_until` null = tak berujung) → `overlapping_override` (field `valid_from`).
13. Simpan. Saat create `approved_by = $approver->id`; saat edit `approved_by` tetap (pengubah tercatat sebagai causer di Activitylog). Mengembalikan model.

### `EndFeeOverride::handle(FeeOverride $override, ?CarbonInterface $endOn = null): FeeOverride`
Mengakhiri keringanan tanpa menghapus baris (pola `EndSupervision`). `$endOn` bawaan `today()`.
1. `$override->valid_until` terisi dan `<= $endOn` → `override_already_ended` (field `valid_until`).
2. `$endOn < valid_from` → `end_before_start` (field `valid_until`).
3. `valid_until = $endOn` (memperpendek keringanan yang punya tanggal akhir lebih lambat juga boleh). Efek samping: satu activity `updated`.

### `ResolveFeeOverride`
Memilih satu keringanan yang aktif di bulan tagihan. Murni baca, tidak bergantung user login (`FeeOverride` tanpa global scope).

- `forStudent(Student $student, FeeType $feeType, CarbonInterface $period): ?FeeOverride`
- `forApplicant(int $applicantId, FeeType $feeType, CarbonInterface $period): ?FeeOverride` (hanya baris dengan `applicant_id` itu; dipakai F010–F012)

Aturan:
1. Bulan tagihan = `$period->startOfMonth()` sampai `$period->endOfMonth()` (tanggal mana pun di bulan itu memberi hasil sama).
2. Kandidat = keringanan pemilik dan jenis biaya itu dengan `activeBetween(awal bulan, akhir bulan)`. Keringanan yang mulai setelah akhir bulan atau berakhir sebelum awal bulan diabaikan.
3. Bila lebih dari satu (hanya mungkin bila satu berakhir dan yang lain mulai di bulan yang sama, karena tumpang tindih ditolak `SaveFeeOverride`): pilih `valid_from` terbaru, lalu `id` terbesar.
4. Status `is_active` program beasiswa tidak memengaruhi keringanan yang sudah ada.
5. Tidak ada kandidat → `null`.

### `ApplyFeeOverride::handle(?int $baseAmount, ?FeeOverride $override): ?array`
Murni hitung, tanpa query. Mengembalikan `array{base_amount: int, discount_amount: int, amount: int, fee_override_id: int|null}` atau `null` (shape didokumentasikan di PHPDoc). Selalu berlaku `base_amount - discount_amount = amount` dan ketiganya int `>= 0`.

| Tarif dasar | Keringanan | Hasil |
|---|---|---|
| null | null, potongan, persen | `null` (pemanggil mencatat "tarif belum diatur" dan melewati) |
| null | nominal V | base V, diskon 0, amount V (termasuk V = 0) |
| B | null | base B, diskon 0, amount B, `fee_override_id` null |
| B | nominal V | amount `min(V, B)`, diskon `B - amount` (keringanan tidak pernah menaikkan tagihan) |
| B | potongan V | amount `max(0, B - V)`, diskon `B - amount` |
| B | persen P | diskon `min(B, intdiv(B * P, 100))`, amount `B - diskon` (pembulatan diskon ke bawah) |

`fee_override_id` = id keringanan bila keringanan ikut dipakai.

## UI (Filament / Blade)

Rujukan design-guide: §0 (checklist), §3 (ikon `Banknotes` untuk keduanya, sesuai konsep Keuangan; tidak ada ikon baru), §4 (tidak ada status/badge baru; masa berlaku tampil sebagai tanggal, bukan badge), §5 (uang `->money('IDR', locale: 'id', decimalPlaces: 0)`, input `TextInput` integer + `->prefix('Rp')`, persen `50%` tanpa desimal, nol untuk beasiswa penuh tampil `Gratis`, tanggal `->date('j M Y')`, kosong `-`), §6 (halaman `Manage…` modal, ≤7 kolom, filter jenjang `accessible()`, aksi selain Edit di `ActionGroup`, aksi bisnis sebagai static method Resource), §7 (empty state, notifikasi), §8 (tidak ada Hapus; "Akhiri keringanan" dengan konfirmasi yang menjelaskan akibat), §9 (pesan validasi menyebut cara memperbaiki). Implementer wajib memanggil `/anti-ui-slop` bila tersedia dan menjalankan checklist §0.

Kedua resource memakai `BelongsToModule` (`getModule(): 'keuangan'`) dan `ShowsActionErrorsOnForm`, grup `pondok.navigation.keuangan`.

### `ScholarshipResource` — "Program beasiswa" (`app/Filament/Resources/Scholarships/`)
Halaman `ManageScholarships`, navigationSort 3.
- Form (modal, 2 kolom): `name`, `sponsor` (helper "Donatur atau yayasan. Kosongkan bila dari lembaga sendiri."), `mode` (Select, label `pondok.fee_override.modes.*`, `live`), `default_value` (integer `minValue(0)`; prefix `Rp` bila mode nominal/potongan, suffix `%` dan `maxValue(100)` bila persen), `is_active` (Toggle, bawaan aktif).
- Create dan Edit memanggil `SaveScholarship` lewat `->using(...)` + `saveShowingFormErrors`.
- Tabel: `name`, `sponsor` (placeholder `-`), keringanan bawaan (teks dari `FeeOverride`-style formatter: "Gratis", "Bayar Rp 250.000", "Potongan Rp 100.000", "Potongan 50%"), `fee_overrides_count` ("Penerima", rata kanan), `is_active` (IconColumn). `defaultSort('name')`. Filter `is_active` (TernaryFilter).
- Aksi baris: Edit saja. Tidak ada Delete maupun bulk delete.
- Empty state: `pondok.scholarship.empty` = "Belum ada program beasiswa. Tambahkan program seperti Beasiswa Tahfidz atau Yatim."

### `FeeOverrideResource` — "Keringanan" (`app/Filament/Resources/FeeOverrides/`)
Halaman `ManageFeeOverrides`, navigationSort 4.
- `getEloquentQuery()`: `whereNotNull('student_id')->whereHas('student')`. `whereHas` membawa global scope `Student` sehingga user bercakupan jenjang hanya melihat keringanan santri jenjangnya. Keringanan milik pendaftar saja (tanpa `student_id`) tidak tampil di F005.
- Form (modal, 2 kolom, urutan: santri → biaya → keringanan → masa berlaku → alasan):
  - `student_id`: Select searchable, opsi `Student::query()->active()` (global scope cakupan berlaku), label "NIS — Nama"; `disabled()` saat edit.
  - `fee_type_id`: Select jenis biaya aktif (plus nilai sekarang saat edit).
  - `scholarship_id`: Select opsional, program aktif (plus nilai sekarang saat edit), placeholder "Keringanan khusus (bukan beasiswa)", `live`; saat dipilih mengisi `mode` dan `value` dari `mode`/`default_value` program (`afterStateUpdated`).
  - `mode`: Select `pondok.fee_override.modes.*`, `live`, helper per mode (`pondok.fee_override.mode_helpers.*`, mis. persen: "Mengikuti tarif: potongan menyesuaikan bila tarif naik.").
  - `value`: integer `minValue(0)`; prefix `Rp` untuk nominal/potongan, suffix `%` dan `maxValue(100)` untuk persen; helper nominal "Isi 0 untuk gratis."
  - `valid_from`: DatePicker wajib, bawaan tanggal 1 bulan ini.
  - `valid_until`: DatePicker opsional, helper "Kosongkan bila berlaku sampai lulus."
  - `reason`: TextInput wajib, `maxLength(255)`, `columnSpanFull()`, helper "Contoh: hafal 10 juz, anak yatim, kesepakatan dengan wali."
- Create memanggil `SaveFeeOverride::handle($data, auth()->user())`; Edit memanggil `handle($data, auth()->user(), $record)`. Error Action tampil di field lewat `saveShowingFormErrors`.
- Tabel (≤7 kolom): `student.nis` (pengenal), `student.name`, `feeType.name`, `scholarship.name` (placeholder `pondok.fee_override.no_scholarship` = "Keringanan khusus"), keringanan (teks terformat, rata kanan, `tabular-nums`), `valid_from` (date), `valid_until` (date, placeholder `pondok.fee_override.until_graduation` = "Sampai lulus"); `approver.name` dan `reason` toggleable tersembunyi. Eager-load `student`, `feeType`, `scholarship`, `approver`. `defaultSort('valid_from', 'desc')`.
- Format kolom keringanan lewat satu method (mis. `FeeOverride::valueLabel(): string`) memakai kunci lang: nominal 0 → `pondok.fee_override.value_labels.free` "Gratis"; nominal → "Bayar :amount"; potongan → "Potongan :amount"; persen → "Potongan :percent%". `:amount` diformat `Rp 250.000` (titik ribuan, tanpa desimal). Dipakai juga oleh `ScholarshipResource` untuk nilai bawaan program (method statis bersama yang menerima mode dan nilai).
- Filter: jenis biaya, program beasiswa, jenjang (`relationship` lewat `student.unit` dengan opsi `accessible()`), dan `validity` (SelectFilter: `aktif` = `activeOn(today())`, `akan_datang` = `valid_from > today`, `berakhir` = `valid_until < today`).
- Aksi baris: `EditAction` tampil langsung; `ActionGroup` berisi `FeeOverrideResource::endAction()` ("Akhiri keringanan", warna `danger`, ikon tidak baru): modal DatePicker `end_on` bawaan hari ini, `requiresConfirmation`, deskripsi `pondok.fee_override.end_description` = "Keringanan tidak dipakai untuk tagihan setelah tanggal ini. Data tetap tersimpan." Memanggil `EndFeeOverride`; error `ValidationException` ditampilkan sebagai notifikasi `danger` atau error field `end_on` (pola `RoomAssignmentResource::vacate`). Tersembunyi bila `valid_until` terisi dan `< today()`. Butuh permission `update`. Notifikasi sukses menyebut santri dan jenis biaya.
- Tidak ada Delete maupun bulk delete.
- Empty state: `pondok.fee_override.empty` = "Belum ada keringanan. Tambahkan keringanan untuk santri penerima beasiswa."

### Penyesuaian `EditUser`
`DeleteAction` disembunyikan bila user adalah dirinya sendiri (sudah ada) **atau** `approvedFeeOverrides()->exists()`. User dinonaktifkan lewat `is_active`.

### Teks `lang/id/pondok.php` (kunci baru)
- `scholarship.*`: label "Program beasiswa", plural, name "Nama program", sponsor "Donatur", sponsor_helper, mode "Mode bawaan", default_value "Nilai bawaan", default_value_label (kolom tabel "Keringanan bawaan"), overrides_count "Penerima", is_active "Aktif", empty.
- `fee_override.*`: label "Keringanan", plural "Keringanan", student "Santri", fee_type "Jenis biaya", scholarship "Program beasiswa", no_scholarship, mode "Mode", modes [nominal="Bayar nominal", potongan="Potongan rupiah", persen="Potongan persen"], mode_helpers.*, value "Nilai", value_column "Keringanan", value_labels [free, nominal, potongan, persen], valid_from "Berlaku mulai", valid_until "Berlaku sampai", valid_until_helper, until_graduation, reason "Alasan", reason_helper, approver "Disetujui oleh", validity "Masa berlaku", validity_options [aktif="Berlaku hari ini", akan_datang="Belum mulai", berakhir="Sudah berakhir"], end_action "Akhiri keringanan", end_on "Berakhir pada", end_description, ended (notifikasi sukses, mis. "Keringanan :fee_type untuk :student berakhir pada :date."), empty.
- `keuangan.errors.*` (tanpa placeholder, tampil sebagai error field): `name_required`, `scholarship_name_taken`, `invalid_mode`, `value_invalid`, `percent_range`, `owner_required`, `student_not_found`, `student_not_active`, `fee_type_not_found`, `scholarship_not_found`, `scholarship_inactive`, `reason_invalid`, `date_invalid`, `until_before_from`, `overlapping_override`, `override_already_ended`, `end_before_start`. Contoh: `overlapping_override` = "Santri ini sudah punya keringanan untuk jenis biaya yang sama pada rentang tanggal ini. Akhiri keringanan lama terlebih dahulu atau ubah tanggal mulai."; `percent_range` = "Persen harus bilangan bulat dari 0 sampai 100."; `until_before_from` = "Tanggal berakhir harus sama dengan atau setelah tanggal mulai."

## Permission & cakupan

### Permission baru
Tambah `'Scholarship', 'FeeOverride'` ke `RolePermissionSeeder::RESOURCE_MODELS`, lalu:
`php artisan shield:generate --option=policies --resource=ScholarshipResource,FeeOverrideResource --panel=admin -n`
(bila Shield tidak membuat policy, salin pola `FeeRatePolicy`). Nama: `view-any:scholarship`, `create:fee-override`, dst.

### Pembagian ke peran
| Peran | Permission |
|---|---|
| super-admin | semua (otomatis) |
| bendahara | `Scholarship` dan `FeeOverride`: `viewAny`, `view`, `create`, `update` (`$withoutDelete`) |
| peran lain | tidak ada (admin-psb mendapat akses keringanan pendaftar di F010/F011 bila perlu) |

Dataset `bendahara` di `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` menjadi persis: `view-dashboard`, `view-finance-metrics`, `view-any:fee-type`, `view:fee-type`, `create:fee-type`, `update:fee-type`, `view-any:fee-rate`, `view:fee-rate`, `create:fee-rate`, `update:fee-rate`, `view-any:scholarship`, `view:scholarship`, `create:scholarship`, `update:scholarship`, `view-any:fee-override`, `view:fee-override`, `create:fee-override`, `update:fee-override`. Dataset peran lain tidak berubah. Komentar seeder bendahara diperbarui.

### Cakupan jenjang
- `Scholarship` lintas jenjang, tidak dibatasi.
- `FeeOverride` **tidak** memakai `HasUnitScope`: keringanan pendaftar belum punya santri (dan tabel `applicants` belum ada), dan resolver dipanggil scheduler tanpa user. Cakupan di UI ditegakkan lewat `getEloquentQuery()` (`whereHas('student')` membawa global scope `Student`), opsi santri di form (`Student::query()`), filter jenjang `accessible()`, dan di Action lewat pencarian `Student::query()` (santri di luar cakupan → `student_not_found`).

### Saklar modul
Memakai `modules.keuangan.enabled` yang sudah ada (aktif bawaan sejak F004). Saat false: `/admin/scholarships` dan `/admin/fee-overrides` 403 dan tidak tampil di navigasi. Action tetap bisa dipanggil dari kode.

## Kriteria penerimaan (bisa dites)

Fixture bersama: jenjang SD dan SLTP; jenis biaya `SPP` (bulanan, semua); santri aktif SLTP `A`, santri aktif SLTP `B`, santri aktif SD `C`; user bendahara `U` tanpa cakupan. Periode tagihan contoh Oktober 2026. Tanggal "hari ini" dipatok `2026-10-15` lewat `travelTo`/`Carbon::setTestNow` bila relevan.

Penerapan keringanan (`ApplyFeeOverride`) — tarif dasar 400.000 kecuali disebut lain
- AC-1: Tanpa keringanan → `['base_amount' => 400000, 'discount_amount' => 0, 'amount' => 400000, 'fee_override_id' => null]`.
- AC-2: Mode nominal: nilai 250.000 → amount 250.000, diskon 150.000, `fee_override_id` = id keringanan; nilai 0 → amount 0, diskon 400.000; nilai 500.000 (di atas tarif) → amount 400.000, diskon 0.
- AC-3: Mode potongan: nilai 100.000 → amount 300.000, diskon 100.000; nilai 500.000 → amount 0, diskon 400.000.
- AC-4: Mode persen: 50 → amount 200.000, diskon 200.000; 100 → amount 0; 0 → amount 400.000, diskon 0. Pembulatan: tarif 333.333 persen 50 → diskon 166.666, amount 166.667; tarif 100.001 persen 33 → diskon 33.000, amount 67.001.
- AC-5: Tarif dasar `null`: nominal 250.000 → `base_amount` 250.000, diskon 0, amount 250.000, `fee_override_id` terisi; nominal 0 → ketiganya 0; potongan, persen, dan tanpa keringanan → `null`.
- AC-6: Untuk seluruh dataset AC-1–AC-5 yang tidak `null`: `base_amount - discount_amount === amount`, ketiganya bertipe `int` (bukan float) dan `>= 0`.
- AC-7: Keringanan yang sama diterapkan ke tarif 400.000 lalu 450.000 (tarif naik): potongan 100.000 → 300.000 lalu 350.000; persen 50 → 200.000 lalu 225.000; nominal 250.000 → 250.000 pada keduanya.

Pemilihan keringanan (`ResolveFeeOverride`) — masa berlaku
- AC-8: Untuk santri `A` dan SPP, periode `2026-10-01`, dataset satu keringanan per kasus: `valid_from 2026-11-01` → `null`; `valid_until 2026-09-30` → `null`; `2026-07-01..2026-10-01` → ditemukan; `valid_from 2026-10-31` tanpa akhir → ditemukan; `2025-07-01..null` → ditemukan. Periode `2026-10-15` dan `2026-10-31` memberi hasil sama dengan `2026-10-01`.
- AC-9: Keringanan santri `B`, keringanan `A` untuk jenis biaya lain, dan keringanan milik pendaftar saja (`student_id` null, `applicant_id` 77) tidak pernah dikembalikan untuk `forStudent(A, SPP, ...)`. `forApplicant(77, SPP, 2026-10-01)` mengembalikan keringanan pendaftar itu; `forApplicant(78, ...)` → `null`.
- AC-10: Dua keringanan `A`+SPP: lama `2026-01-01..2026-10-15` dan baru `2026-10-16..null` → periode Oktober 2026 mengembalikan yang baru; September 2026 yang lama; November 2026 yang baru.
- AC-11: Hasil sama saat dipanggil tanpa user login maupun saat login sebagai bendahara bercakupan SD saja (keringanan santri SLTP tetap ditemukan). Keringanan yang programnya kemudian dinonaktifkan tetap dikembalikan.

Program beasiswa (`SaveScholarship`)
- AC-12: Data valid (`name` " Beasiswa Tahfidz ", mode persen, `default_value` "50", sponsor "") → tersimpan `name` "Beasiswa Tahfidz", `default_value` int 50, `sponsor` null, `is_active` true.
- AC-13: Gagal tanpa perubahan DB, dataset: `name` "  " → `name_required`; `name` yang sudah ada → `scholarship_name_taken`; `mode` "gratis" → `invalid_mode`; `default_value` -1, "12.5", null → `value_invalid`; mode persen dengan 101 → `percent_range`. Edit program tanpa mengubah nama tidak bentrok dengan dirinya.
- AC-14: Mengubah program (mode potongan 100.000 → persen 25) tidak mengubah `mode`/`value` keringanan yang sudah memakai program itu.

Keringanan (`SaveFeeOverride`)
- AC-15: Create valid untuk `A` (mode nominal, `value` "250000", `valid_from` "2026-07-01", `valid_until` null, `reason` " Hafal 10 juz ") dengan approver `U` → satu baris: `value` int 250000, `reason` "Hafal 10 juz", `approved_by` = `U`, `scholarship_id` null diterima (keringanan khusus). Dengan `scholarship_id` program persen 50 dan input mode potongan 100.000 → yang tersimpan mode dan nilai input, bukan bawaan program.
- AC-16: Gagal tanpa baris baru, dataset field → reason: pemilik kosong → `student_id`/`owner_required`; santri id tidak ada → `student_not_found`; santri terhapus lunak → `student_not_found`; santri status `lulus` → `student_not_active`; jenis biaya tidak ada → `fee_type_id`/`fee_type_not_found`; program tidak ada → `scholarship_id`/`scholarship_not_found`; program nonaktif → `scholarship_inactive`; mode "gratis" → `mode`/`invalid_mode`; value -1, "1.5", null → `value`/`value_invalid`; persen 101 → `percent_range`; reason "  " atau 256 karakter → `reason`/`reason_invalid`; `valid_from` null atau "15-10-2026" → `valid_from`/`date_invalid`; `valid_until` "bukan-tanggal" → `valid_until`/`date_invalid`; `valid_until` 2026-06-30 dengan `valid_from` 2026-07-01 → `valid_until`/`until_before_from`. `valid_until` sama dengan `valid_from` diterima.
- AC-17: Tumpang tindih: ada `A`+SPP `2026-07-01..null` → baru `A`+SPP `2027-01-01..null` gagal `overlapping_override` (field `valid_from`); baru `2026-01-01..2026-07-01` gagal (batas inklusif); baru `2026-01-01..2026-06-30` berhasil; baru `A` jenis biaya lain dan baru `B` SPP berhasil. Bila yang lama `2026-07-01..2026-12-31`, baru `2027-01-01..null` berhasil. Dua keringanan pendaftar saja dengan `applicant_id` sama dan rentang beririsan → gagal; `applicant_id` berbeda → berhasil. Edit keringanan itu sendiri tanpa mengubah tanggal berhasil.
- AC-18: Cakupan di Action: login sebagai bendahara bercakupan SLTP, create untuk santri SD `C` → `student_not_found`; untuk `A` berhasil. Bendahara tanpa cakupan berhasil untuk `A` dan `C`.
- AC-19: Edit oleh user lain `V`: `value`, `mode`, `reason`, dan tanggal berubah; `student_id`/`applicant_id` di data edit diabaikan (pemilik tetap); `approved_by` tetap `U`. Program yang sudah dinonaktifkan tetap boleh bila `scholarship_id` tidak diubah; mengganti ke program nonaktif lain → `scholarship_inactive`.
- AC-20: Pendaftar: create dengan `student_id` null dan `applicant_id` 77 → tersimpan tanpa error walau tabel `applicants` belum ada; create dengan `student_id` `A` dan `applicant_id` 77 → keduanya tersimpan.

Mengakhiri keringanan (`EndFeeOverride`)
- AC-21: Keringanan `A`+SPP `2026-07-01..null`, hari ini 2026-10-15: `handle($o, 2026-10-31)` → `valid_until` 2026-10-31, baris tetap ada (jumlah baris tidak berubah); `ResolveFeeOverride` November 2026 → `null`, Oktober 2026 → ditemukan. Tanpa `$endOn` → `valid_until` 2026-10-15. Keringanan dengan `valid_until` 2026-12-31 diakhiri 2026-10-31 → berhasil (diperpendek).
- AC-22: Gagal tanpa perubahan: `$endOn` 2026-06-30 untuk keringanan mulai 2026-07-01 → `valid_until`/`end_before_start`; keringanan dengan `valid_until` 2026-09-30 diakhiri 2026-10-31 → `override_already_ended`; diakhiri 2026-09-30 → `override_already_ended`.

Constraint database
- AC-23: Menghapus jenis biaya, program beasiswa, atau user approver yang dirujuk `fee_overrides` → `QueryException`, data tetap. `forceDelete()` santri yang punya keringanan → `QueryException`; `delete()` (lunak) berhasil dan keringanannya tetap ada. `scholarships.name` ganda lewat insert langsung → `UniqueConstraintViolationException`. Insert langsung keringanan dengan `applicant_id` 999 (tanpa baris pendaftar) berhasil.
- AC-24: Kolom `fee_overrides.value` dan `scholarships.default_value` bertipe integer menurut `Schema::getColumns` (terima `integer`, `bigint`, `int8`). Ada index dengan kolom tepat (`student_id`, `fee_type_id`, `valid_from`) menurut `Schema::getIndexes`.

Activitylog
- AC-25: Login `U`, create keringanan lewat `SaveFeeOverride` → tepat satu activity `log_name = 'keuangan'`, `event = 'created'`, subject keringanan itu, `causer_id = U`, `properties.attributes` memuat `mode`, `value`, `valid_from`. Edit `value` 250000 → 200000 → activity `updated` dengan `old.value = 250000`, `attributes.value = 200000`. `EndFeeOverride` → activity `updated` dengan `attributes.valid_until`. Simpan ulang tanpa perubahan → tidak ada activity baru.
- AC-26: Create dan edit program lewat `SaveScholarship` → activity `created` dan `updated` dengan `log_name = 'keuangan'` dan subject program itu.

Akses, saklar modul, cakupan (HTTP/Livewire)
- AC-27: `RolePermissionSeeder` memberi bendahara persis daftar di bagian Permission; dataset peran lain tidak berubah; test "creates every permission that a generated policy checks" tetap lulus.
- AC-28: Bendahara: `GET /admin/scholarships` dan `/admin/fee-overrides` → 200. admin-sekolah, kesantrian, musyrif, admin-konten, admin-psb, ustadz-tahfidz, petugas-pos → 403 pada keduanya.
- AC-29: `modules.keuangan.enabled = false` → super-admin 403 di kedua URL dan label navigasi "Program beasiswa"/"Keringanan" tidak tampil di `/admin`.
- AC-30: Bendahara bercakupan SLTP di `ManageFeeOverrides`: melihat keringanan `A`, tidak melihat keringanan `C` (SD) (`assertCanSeeTableRecords`/`assertCanNotSeeTableRecords`); opsi santri di form create hanya santri aktif SLTP (tidak memuat `C` dan tidak memuat santri SLTP berstatus `lulus`). Keringanan pendaftar saja (`student_id` null) tidak tampil bagi siapa pun, termasuk super-admin.

UI aksi (Livewire)
- AC-31: Bendahara membuat keringanan dari `ManageFeeOverrides`: memilih program persen 50 mengisi `mode` = persen dan `value` = 50 di form; simpan → baris baru dengan `approved_by` = user login. Create yang tumpang tindih → error `overlapping_override` tampil di field `valid_from`, jumlah baris tetap. Edit nilai → tersimpan. Tidak ada aksi `delete` di tabel (`assertTableActionDoesNotExist('delete')`) dan tidak ada bulk delete.
- AC-32: Kolom keringanan menampilkan "Gratis" untuk nominal 0, "Bayar Rp 250.000" untuk nominal 250.000, "Potongan Rp 100.000" untuk potongan 100.000, dan "Potongan 50%" untuk persen 50; `valid_until` null tampil "Sampai lulus". Filter `validity` (hari ini 2026-10-15): `aktif` hanya memuat keringanan yang berlaku hari itu, `akan_datang` hanya yang mulai setelahnya, `berakhir` hanya yang berakhir sebelumnya.
- AC-33: Aksi "Akhiri keringanan" pada keringanan `2026-07-01..null` dengan `end_on` 2026-10-31 → `valid_until` tersimpan 2026-10-31. Aksi tersembunyi (`assertTableActionHidden`) untuk keringanan dengan `valid_until` 2026-09-30; tampil untuk `valid_until` null dan 2026-12-31. `end_on` sebelum `valid_from` → pesan `end_before_start` tampil (error field atau notifikasi danger), `valid_until` tetap null.
- AC-34: Bendahara membuat program dari `ManageScholarships` → tersimpan; nama ganda → error field `name` tampil, jumlah baris tetap; edit `is_active` → tersimpan; tidak ada aksi `delete` maupun bulk delete; tabel kosong menampilkan `pondok.scholarship.empty`.
- AC-35: Super-admin membuka `EditUser` milik user lain yang menjadi approver keringanan → `DeleteAction` tersembunyi (`assertActionHidden('delete')`); untuk user lain tanpa keringanan → tampil.

## Rencana test

Catatan: helper Pest bersifat global. Jangan mendefinisikan ulang helper yang ada (`keuanganUser`, `keuanganAssertRejected`, `keuanganFixture`, dst.); helper baru memakai awalan `keringanan` (mis. `keringananFixture()`, `keringananOverride()`, `keringananOverrideData()`, `keringananScholarshipData()`). `keuanganAssertRejected` dipakai ulang untuk semua kasus gagal.

| AC | File | Nama test yang diusulkan |
|---|---|---|
| AC-1 – AC-7 | `tests/Feature/Actions/Keuangan/ApplyFeeOverrideTest.php` | `it keeps the base amount without an override`; `it applies the nominal mode` (dataset); `it applies the discount mode` (dataset); `it applies the percent mode and rounds the discount down` (dataset); `it handles a missing base rate`; `it keeps base minus discount equal to the amount` (dataset); `it follows a rate increase for discount and percent modes` |
| AC-8 – AC-11 | `tests/Feature/Actions/Keuangan/ResolveFeeOverrideTest.php` | `it applies only overrides active in the billing month` (dataset); `it ignores other students, fee types, and applicant overrides`; `it resolves applicant overrides`; `it picks the latest override within the same month`; `it resolves regardless of the signed-in user scope or scholarship status` |
| AC-12 – AC-14 | `tests/Feature/Actions/Keuangan/SaveScholarshipTest.php` | `it saves a scholarship`; `it rejects the scholarship` (dataset); `it updates a scholarship without clashing with itself`; `it does not change existing overrides when the scholarship changes` |
| AC-15 – AC-20 | `tests/Feature/Actions/Keuangan/SaveFeeOverrideTest.php` | `it saves an override with the approver`; `it keeps the given mode and value over scholarship defaults`; `it rejects the override` (dataset); `it rejects overlapping overrides` (dataset); `it allows adjacent or unrelated overrides`; `it respects the user unit scope`; `it edits an override keeping the owner and approver`; `it validates scholarship status only when it changes`; `it saves applicant overrides without an applicants table` |
| AC-21, AC-22 | `tests/Feature/Actions/Keuangan/EndFeeOverrideTest.php` | `it ends an override without deleting it`; `it ends today by default`; `it shortens a planned end date`; `it rejects ending` (dataset) |
| AC-23, AC-24 | `tests/Feature/Database/KeuanganConstraintsTest.php` (tambah) | `it restricts deleting records referenced by overrides`; `it keeps overrides when a student is soft deleted`; `it rejects a duplicate scholarship name`; `it stores applicant overrides without a foreign key`; `it stores override values as integer columns`; `it indexes overrides by student, fee type, and start date` |
| AC-25, AC-26 | `tests/Feature/Models/KeuanganActivityLogTest.php` (tambah) | `it logs override creation with the causer`; `it logs override changes and endings`; `it logs scholarship changes` |
| AC-27 | `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (ubah dataset) | dataset `bendahara` di `grants each role exactly its permissions` |
| AC-28, AC-29 | `tests/Feature/Filament/KeuanganAccessTest.php` (tambah) | `it lets bendahara manage scholarships and overrides`; `it forbids scholarship and override pages to other roles` (dataset); `it returns 403 for scholarship and override pages when the module is disabled` |
| AC-30 – AC-33 | `tests/Feature/Filament/Resources/FeeOverrides/FeeOverrideResourceTest.php` | `it limits overrides and student options to the user units`; `it hides applicant-only overrides`; `it creates an override from the panel with scholarship defaults`; `it shows the overlapping override error`; `it edits an override`; `it has no delete action`; `it formats override values` (dataset); `it filters overrides by validity` (dataset); `it ends an override from the table`; `it hides the end action for ended overrides`; `it shows the end before start error` |
| AC-34 | `tests/Feature/Filament/Resources/Scholarships/ScholarshipResourceTest.php` | `it creates a scholarship from the panel`; `it shows the taken name error`; `it edits a scholarship`; `it has no delete action`; `it shows the empty state` |
| AC-35 | `tests/Feature/Filament/Resources/Users/UserResourceTest.php` (tambah) | `it hides delete for users who approved overrides` |

## Urutan implementasi

1. Migration `scholarships` lalu `fee_overrides` (FK `restrictOnDelete`, `applicant_id` tanpa FK, index ERD) + model + factory + relasi di `Student`, `FeeType`, `User` + Activitylog. Test: AC-23, AC-24.
2. Action `ApplyFeeOverride` (murni). Test: AC-1 – AC-7.
3. Action `ResolveFeeOverride` + scope `activeBetween`/`activeOn`. Test: AC-8 – AC-11.
4. Action `SaveScholarship` + kunci error. Test: AC-12 – AC-14, AC-26.
5. Action `SaveFeeOverride` + kunci error. Test: AC-15 – AC-20, bagian create/edit AC-25.
6. Action `EndFeeOverride`. Test: AC-21, AC-22, bagian end AC-25.
7. Permission: `RESOURCE_MODELS`, peran bendahara, policy Shield, update `RolePermissionSeederTest`. Test: AC-27.
8. `ScholarshipResource` + lang. Test: bagian scholarships AC-28/AC-29, AC-34.
9. `FeeOverrideResource` (form, tabel, filter, aksi akhiri) + lang. Test: bagian fee-overrides AC-28/AC-29, AC-30 – AC-33.
10. `EditUser` Delete tersembunyi untuk approver. Test: AC-35.
11. Pint, checklist design-guide §0, jalankan seluruh test Keuangan, User, dan seeder.

## Risiko & pertanyaan terbuka

Pertanyaan (default dipakai agar kerja lanjut tanpa menunggu user):
1. **Lebih dari satu keringanan di bulan yang sama.** Default: tumpang tindih untuk pemilik + jenis biaya yang sama ditolak saat simpan; bila dua keringanan bersambung dalam satu bulan, yang `valid_from`-nya terbaru dipakai untuk bulan itu. Keringanan tidak ditumpuk (beasiswa + keringanan khusus untuk jenis biaya yang sama tidak dijumlahkan).
2. **"Aktif di bulan tagihan"** berarti rentang keringanan beririsan dengan bulan itu (bukan hanya aktif pada tanggal 1). Konsekuensi: keringanan yang mulai tanggal 20 sudah berlaku untuk tagihan bulan itu, dan keringanan yang berakhir tanggal 5 masih berlaku untuk bulan itu. Tidak ada pro-rata.
3. **Nominal di atas tarif.** Default: dibatasi tarif (`min(V, B)`), karena keringanan tidak boleh menaikkan tagihan dan `discount_amount` tidak boleh negatif.
4. **Pembulatan persen.** Default: diskon dibulatkan ke bawah (`intdiv`), sehingga sisa pembulatan ditanggung pembayar, sesuai pseudocode ERD.
5. **Cara mengakhiri.** Default: `EndFeeOverride` mengisi `valid_until` (baris tidak dihapus). Koreksi kesalahan input (nilai, mode, tanggal) lewat Edit dan tercatat di Activitylog. Membatalkan keringanan yang belum mulai sama sekali belum punya mekanisme khusus selain Edit; bila perlu status `batal`, ditinjau di F006 setelah ada tagihan yang merujuk `fee_override_id`.
6. **Edit keringanan yang sudah dipakai tagihan.** Default di F005: Edit diizinkan penuh (belum ada tagihan). Tagihan tetap aman karena nominal item adalah snapshot. F006 boleh membatasi Edit nilai/mode untuk keringanan yang sudah dirujuk `invoice_items` (mengarahkan ke "akhiri lalu buat baru").
7. **`approved_by`.** Default: wajib, diisi user yang membuat; tidak berubah saat Edit. Pengubah tercatat di Activitylog. Akibatnya user approver tidak bisa dihapus (Delete disembunyikan; user dinonaktifkan).
8. **Santri tidak aktif.** Default: keringanan baru hanya untuk santri `aktif`; keringanan santri yang kemudian lulus/keluar tetap tersimpan dan diabaikan karena generator hanya menagih santri aktif.
9. **Persen berdesimal** (12,5%). Default: tidak didukung; persen bilangan bulat 0–100.
10. **Admin PSB mengelola keringanan pendaftar.** Default: tidak di F005; ditinjau di F010/F011.
11. **Nama program unik.** Default: ya (Action + DB unique), agar laporan subsidi per program tidak ambigu. ERD tidak menyebut unique; ini tambahan kecil.

Risiko:
- F010 akan menambah FK `fee_overrides.applicant_id` → `applicants`. Di SQLite Laravel membangun ulang tabel; implementer F010 memastikan index (`student_id`, `fee_type_id`, `valid_from`) dan FK lain tetap ada setelah migration (pola risiko F004/F009).
- `whereHas('student')` di `FeeOverrideResource` menyembunyikan keringanan santri yang terhapus lunak. Dianggap benar (santri terhapus tidak dikelola), tetapi laporan subsidi kelak harus memakai `withTrashed`.
- Perbandingan tanggal wajib `whereDate` dengan string `Y-m-d` agar konsisten di SQLite (kolom `date` disimpan sebagai teks) dan MySQL/Postgres.
- Activitylog: `logOnly` memakai daftar fillable; cast `date` tercatat sebagai string tanggal. Test AC-25 jangan bergantung pada format jam.
- `ApplyFeeOverride` mengembalikan array, bukan DTO, agar tidak membuat folder baru (`app/Data` dsb.). Bila F006 membutuhkan DTO, usulkan saat itu.

Usulan entri baru (belum ditambahkan ke `feature_list.json`, menunggu keputusan user):
- `keuangan-keringanan-massal`: menerapkan satu program beasiswa ke banyak santri sekaligus (pilih santri/angkatan, jenis biaya, masa berlaku).

## Riwayat revisi

- DRAFT 1 (product-manager): rencana awal. Jumlah AC: 35.

## Review #1 — 2026-10-15

Verdict: APPROVED

Default PM 1–11 diterima orkestrator. Rumus tiga mode, kontrak resolver untuk F006, aturan data keuangan tidak dihapus, activitylog, cakupan jenjang, dan portabilitas tanggal sudah sesuai CLAUDE.md dan ERD. Tidak ada blocker atau major. Temuan minor di bawah ditangani saat RED/GREEN.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | ApplyFeeOverride / Risiko #3 vs ERD "Urutan penentuan nominal" | Mode nominal dibatasi `min(V, B)`, sedangkan pseudocode ERD memakai `'nominal' => $ovr->value`. Rencana menyebut alasannya (diskon tidak boleh negatif) tetapi tidak menyebut bahwa ini menyimpang dari ERD. AC-2 (nilai 500.000 → amount 400.000, diskon 0) dan AC-5 (base null + nominal → base V, diskon 0, amount V, termasuk V = 0) sudah eksplisit, dan perilaku base null sesuai ERD (`$base === null && mode !== 'nominal'` → lewati). | Saat GREEN, tulis di PHPDoc `ApplyFeeOverride` bahwa nominal di atas tarif dibatasi ke tarif sebagai penyimpangan sadar dari pseudocode ERD. Sinkronkan pseudocode ERD (`min`) lewat keputusan user/orkestrator, bukan diam-diam. Test AC-2 tidak boleh dilonggarkan. |
| 2 | minor | SaveFeeOverride langkah 12 | Pengecekan tumpang tindih tidak menyebut harus memakai `activeBetween` (`whereDate` string `Y-m-d`). Di SQLite cast `date` bisa tersimpan dengan jam, jadi perbandingan mentah bisa salah pada batas inklusif (AC-17: `..2026-07-01` vs `2026-07-01..`). | Pakai ulang scope `activeBetween` dengan `$valid_from`/`$valid_until ?? tanggal jauh`, atau `whereDate` setara. AC-17 sudah menguji batas; pastikan lulus di SQLite. |
| 3 | minor | Kontrak resolver F006 | Mode "irisan dengan bulan" berarti keringanan yang berakhir tanggal 5 berlaku penuh untuk tagihan bulan itu, dan yang mulai tanggal 20 juga (PM #2, tanpa pro-rata). Ini beda dari tafsir "aktif pada tanggal 1" tetapi cocok dengan ERD "aktif di bulan tagihan" dan sudah terdokumentasi. | Tidak perlu diubah. F006 wajib memanggil `ResolveFeeOverride` dengan `period`, bukan memfilter sendiri. Catat di plan F006. |
| 4 | minor | Cakupan jenjang | `FeeOverride` tanpa `HasUnitScope` (CLAUDE.md: scope pada `Student`, `Invoice`, `Applicant`, "dan model turunannya"). Alasan diterima (resolver dari scheduler, keringanan pendaftar belum bersantri). Celah: `EditFeeOverride`/`EndFeeOverride` di Action tidak mengecek cakupan santri, hanya UI lewat `getEloquentQuery()`. | Tidak memblokir. Pastikan `FeeOverrideResource::getEloquentQuery()` juga membatasi record action (Filament memakai query itu, jadi aman); tambahkan satu assert di AC-30 bahwa bendahara SLTP tidak bisa memanggil edit/akhiri pada keringanan santri SD. Laporan F008 harus lewat relasi `student`. |
| 5 | minor | AC-25 / Activitylog | "Simpan ulang tanpa perubahan → tidak ada activity" bergantung pada deteksi dirty cast `date` (string `Y-m-d` vs `Y-m-d 00:00:00` di SQLite). Laravel membandingkan nilai date ter-normalisasi, jadi seharusnya aman, tetapi rawan. | Pastikan test memakai nilai tanggal yang sama persis; bila dirty palsu muncul, normalisasi ke `Y-m-d` di Action sebelum `fill`. |
| 6 | minor | Rencana test | Edit yang mengganti `fee_type_id`/tanggal sehingga menabrak keringanan lain belum ada kasusnya. | Tambah satu baris dataset pada `it rejects overlapping overrides`: edit yang menimbulkan irisan → `overlapping_override`. |
