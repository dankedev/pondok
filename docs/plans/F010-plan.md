# F010 psb-pendaftaran-online — Pendaftaran online & cek status

Status rencana: APPROVED

Owner: diklaim orkestrator di `feature_list.json` (product-manager tidak mengubah file itu pada siklus ini). Dependensi: F009 (rencana APPROVED, diimplementasikan sebelum F010) dan F004 (`done`). Rencana ini memakai kontrak F009: tabel `admission_waves`, `admission_tracks`, `admission_requirements`, `document_types`; scope `AdmissionWave::acceptingRegistrationsOn()`, `AdmissionRequirement::forUnit()`; Action `SaveAdmissionRequirement`; resource `AdmissionRequirementResource`; grup navigasi `pondok.navigation.psb`; helper test `psbUser()`, `psbFixture()`.

## Ringkasan & tujuan

Calon wali bisa mendaftarkan anaknya lewat form publik tanpa akun, lalu memantau status, melihat tagihan formulir, dan mengunggah berkas memakai **nomor pendaftaran + token**. Panitia (admin-psb) melihat daftar pendaftar dan memverifikasi berkas (valid/ditolak).

Alur yang dibangun:

1. **Daftar** (Livewire, wizard 4 langkah) → baris `applicants` berstatus `diajukan`, nomor `PSB-YYYY-NNNN`, token acak yang ditampilkan **sekali** (yang disimpan hanya hash-nya).
2. **Tagihan `saat_daftar`** dibuat otomatis (Action) dari `fee_types` berfrekuensi `saat_daftar`, memakai `ResolveFeeRate` (dengan gelombang), `ResolveFeeOverride::forApplicant`, `ApplyFeeOverride`, dan `GenerateInvoiceNumber`.
3. **Formulir lunas** (dicatat bendahara lewat `RecordPayment` yang sudah ada) → unggah berkas terbuka di halaman cek status. Berkas di disk `private`; unggah ulang menimpa baris dan file lama.
4. **Panel admin-psb**: daftar pendaftar (cakupan jenjang), detail, verifikasi berkas, atur ulang token, buat tagihan pendaftaran yang tertinggal, hapus pendaftar (soft delete, khusus spam/ganda).

F010 juga melunasi utang skema dari F002/F005/F006 (FK `applicant_id` di `students`, `fee_overrides`, `invoices`) dan catatan F009/F006 (kuota gelombang, pilihan gelombang bila beberapa terbuka, penjaga hapus syarat berkas, `billing_group` PSB terpisah).

Acuan: PRD 3.4 butir 2–4, PRD 4 (UU PDP), PRD 5 butir 1–2; ERD "Penerimaan Santri Baru (PSB)" (`applicants`, `applicant_documents`, catatan tanpa akun + hash token, disk private), "Biaya PSB" (tahap daftar online dan formulir lunas, tarif per gelombang), tabel constraint (`applicant_documents` unique, `applicants` index, `invoices` unique pendaftar), "Konvensi umum" (soft delete `applicants`, FK keuangan restrict); CLAUDE.md "Otorisasi" (global scope `Applicant`), "Batasan deployment"; design guide §4, §5, §6 (portal/PSB, wizard), §7, §8, §9, §10, §13.

## Ruang lingkup

- Termasuk:
  - Migration, model, factory `applicants` (soft delete, global scope `unit_user`) dan `applicant_documents` (global scope lewat pendaftar).
  - Migration FK `applicant_id` → `applicants` di `students`, `fee_overrides`, `invoices` (`restrictOnDelete`), aman untuk perombakan tabel SQLite.
  - Action `app/Actions/Psb/`: `SubmitApplication`, `GenerateApplicantNumber`, `GenerateRegistrationInvoices`, `UploadApplicantDocument`, `VerifyApplicantDocument`, `CheckApplicantCredentials`, `ResetApplicantToken`, `DeleteApplicant`.
  - Halaman publik Livewire: wizard pendaftaran (`/psb`) dan cek status + unggah berkas (`/psb/status`), route unduh berkas milik sendiri, layout publik minimal, komponen `x-ui.*` dasar.
  - Rate limiting dan anti-spam tanpa layanan pihak ketiga (RateLimiter Laravel di cache store `database`, honeypot, waktu isi minimal, cek pendaftaran ganda).
  - `ApplicantResource` (daftar + detail + relation manager berkas) dan route unduh berkas admin.
  - Kuota gelombang, pilihan gelombang bila beberapa terbuka.
  - Penyesuaian modul lain yang langsung dibutuhkan alur ini: `SaveFeeType` (pemisahan `billing_group` PSB), `SaveAdmissionRequirement` + `AdmissionRequirementResource` (syarat yang sudah dipakai), `SaveFeeOverride` (validasi `applicant_id`), `Invoice::applyUnitScope` dan `Payment::applyUnitScope` (tagihan pendaftar), tampilan pemilik tagihan di `InvoiceResource`, `PaymentResource`, dan kuitansi, penjaga hapus `UnitResource`.
  - Permission `Applicant` dan pembagian ke `admin-psb`.
  - Konfigurasi `trusted_proxies` (env `TRUSTED_PROXIES`) dan `config/livewire.php` (aturan unggah sementara).
  - Penyesuaian test lama yang memakai `applicant_id` fiktif (lihat Rencana test).
- Tidak termasuk (eksplisit):
  - Perubahan status pendaftar ke `diverifikasi`, `diterima`, `cadangan`, `ditolak`, `reviewed_by/at`, `review_note`, tagihan `saat_diterima`, beasiswa jalur (F011). F010 hanya membuat kolomnya.
  - Daftar ulang, pembuatan `students`, penyalinan berkas ke `documents`, FK `documents.source_applicant_document_id` (F012).
  - Penjadwalan/hasil tes seleksi ("formulir lunas membuka ... tes"): tidak ada tabel tes di ERD. F010 hanya membuka unggah berkas.
  - Input pendaftar oleh panitia (pendaftaran offline) dan koreksi data pendaftar oleh panitia (usulan entri baru, lihat Risiko).
  - Pemulihan pendaftar yang dihapus (restore) dan penghapusan permanen.
  - Notifikasi email/WhatsApp berisi token (F021). Token hanya ditampilkan di layar.
  - Widget dashboard PSB (F018) dan tautan PSB dari beranda publik (F017).
  - Perubahan `FinanceReportFilter` (filter jenjang laporan F008 tetap hanya menghitung tagihan santri).
  - Status `draft` tidak dipakai alur publik (data wizard tidak disimpan sebelum dikirim); nilai enum tetap ada sesuai ERD.

## Model data

Urutan migration (timestamp setelah migration F009 terakhir):

1. `..._create_applicants_table`
2. `..._create_applicant_documents_table`
3. `..._add_applicant_foreign_key_to_students_table`
4. `..._add_applicant_foreign_key_to_fee_overrides_table`
5. `..._add_applicant_foreign_key_to_invoices_table`

(Satu migration per tabel, transaksi bawaan Laravel; keputusan orkestrator r3.)

### `applicants` — model `App\Models\Applicant`

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| admission_wave_id | foreignId → admission_waves, `restrictOnDelete` | |
| admission_track_id | foreignId → admission_tracks, `restrictOnDelete` | |
| unit_id | foreignId → units, `restrictOnDelete` | jenjang tujuan |
| registration_no | string(20), unique | `PSB-YYYY-NNNN` |
| access_token_hash | string | `Hash::make` dari token ternormalisasi |
| name | string | |
| nisn | string(10) nullable | 10 digit |
| gender | enum `L`, `P` | |
| birth_place | string(100) | |
| birth_date | date | |
| previous_school | string | |
| guardian_name | string | |
| guardian_phone | string(20) | disimpan tanpa format, awalan `08` |
| email | string nullable | huruf kecil |
| status | enum `draft`, `diajukan`, `diverifikasi`, `diterima`, `cadangan`, `ditolak`, default `diajukan` | |
| reviewed_by | foreignId nullable → users, `restrictOnDelete` | dipakai F011 |
| reviewed_at | timestamp nullable | F011 |
| review_note | text nullable | F011 |
| consented_at | timestamp nullable | **tambahan di luar ERD (keputusan orkestrator r1)**: bukti persetujuan pemrosesan data pribadi (UU PDP). Wajib terisi untuk pendaftaran publik (diisi `SubmitApplication`); nullable di DB hanya untuk baris `draft`/input non-publik di masa depan |
| timestamps, softDeletes | | |

Index ERD: (`admission_wave_id`, `status`), (`created_at`). Unique `registration_no`.

Model:
- `use HasFactory, HasUnitScope, SoftDeletes, LogsActivity`. Global scope default (`unit_id`).
- Konstanta `STATUS_DRAFT` … `STATUS_REJECTED`, `STATUSES`. `statusColor(): string` dan `statusLabel(): string` (satu tempat, design guide §4): `draft`, `diajukan` → `gray`; `diverifikasi` → `info`; `diterima` → `success`; `cadangan` → `warning`; `ditolak` → `danger`. Label dari `pondok.applicant.statuses.*`.
- `$hidden = ['access_token_hash']`; `access_token_hash` tidak fillable (diisi eksplisit oleh Action).
- Cast: `birth_date` date, `reviewed_at` datetime, `consented_at` datetime. `consented_at` tidak fillable (diisi eksplisit oleh Action, bukan dari input klien).
- Activitylog log `psb`, `logOnly(['status'])`, `logOnlyDirty`, `dontSubmitEmptyLogs` (tanpa data pribadi di log).
- Relasi: `admissionWave()`, `admissionTrack()`, `unit()`, `reviewer()`, `documents(): HasMany` (ApplicantDocument), `invoices(): HasMany`, `feeOverrides(): HasMany`, `student(): HasOne` (via `students.applicant_id`, dipakai F012).
- `registrationInvoices(): HasMany` = invoices aktif (status bukan `batal`) yang punya item dengan `feeType.frequency = saat_daftar`.
- `registrationFeeSettled(): bool` = tidak ada `registrationInvoices` yang statusnya bukan `lunas` (tanpa tagihan pendaftaran → `true`).
- `acceptsUploads(): bool` = status `diajukan` dan `registrationFeeSettled()`.
- `tokenFingerprint(): string` = 16 karakter pertama `hash('sha256', access_token_hash)`; dipakai untuk membatalkan sesi saat token diatur ulang.
- `requirements(): Builder` = `AdmissionRequirement::forUnit($this->unit_id)`.

Relasi balik: `AdmissionWave::applicants()`, `AdmissionTrack::applicants()`, `Unit::applicants()`, `AdmissionRequirement::applicantDocuments()`, `Invoice::applicant()` (`withTrashed`), `FeeOverride::applicant()` (`withTrashed`), `Student::applicant()` (`withTrashed`).

Helper gelombang (ditambahkan ke `AdmissionWave`, melengkapi F009):
- `countedApplicants(): int` = pendaftar tidak terhapus dengan status bukan `draft`, **tanpa** cakupan jenjang.
- `isFull(): bool` = `quota !== null && countedApplicants() >= quota`.
- `static openForRegistration(CarbonInterface $today): Collection` = `acceptingRegistrationsOn($today)` yang belum penuh, eager-load `academicYear`, urut `academic_years.starts_on` naik lalu `opens_on`, lalu `id`.

### `applicant_documents` — model `App\Models\ApplicantDocument`

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| applicant_id | foreignId → applicants, `restrictOnDelete` | |
| admission_requirement_id | foreignId → admission_requirements, `restrictOnDelete` | |
| file_path | string | disk `private`, `psb/applicants/{applicant_id}/{uuid}.{ext}` |
| original_name | string | **tambahan di luar ERD** |
| mime_type | string(100) | **tambahan di luar ERD** |
| size_kb | unsignedInteger | **tambahan di luar ERD** |
| status | enum `menunggu`, `valid`, `ditolak`, default `menunggu` | |
| note | text nullable | alasan ditolak |
| verified_by | foreignId nullable → users, `restrictOnDelete` | |
| verified_at | timestamp nullable | |
| timestamps | | |

Unique (`applicant_id`, `admission_requirement_id`) (ERD). Tanpa soft delete.

Tiga kolom tambahan dibutuhkan karena `documents` (ERD Dokumen, F013) mewajibkan `original_name`, `mime_type`, `size_kb`; F012 menyalin berkas valid ke sana. Juga dipakai untuk header unduhan dan tampilan panel. Sinkronkan ERD (lihat Urutan implementasi).

Model: `HasUnitScope` dengan override `applyUnitScope` = `whereIn('applicant_id', Applicant::withoutGlobalScopes()->whereIn('unit_id', $unitIds)->select('id'))` (termasuk pendaftar terhapus). Konstanta `DISK = 'private'`, status, `statusColor()` (`menunggu` → `warning`, `valid` → `success`, `ditolak` → `danger`), `statusLabel()`. Activitylog log `psb`, `logOnly(['status', 'note'])`, dirty only. Relasi `applicant()` (`withTrashed`), `requirement()`, `verifier()`.

### FK `applicant_id` (utang F002/F005/F006)

Tiga migration terpisah (satu tabel per file), memakai **transaksi bawaan** Laravel (tanpa `withinTransaction = false`). Masing-masing: `up()` = `Schema::table('<tabel>', fn ($t) => $t->foreign('applicant_id')->references('id')->on('applicants')->restrictOnDelete())`; `down()` = `dropForeign(['applicant_id'])`. Dipecah per tabel agar kegagalan di satu tabel tidak meninggalkan keadaan setengah jadi dan rollback bisa per langkah; di Postgres DDL tetap atomik per migration.

- MySQL/Postgres: `ALTER TABLE ADD CONSTRAINT`, index `*_applicant_id_index` dipakai ulang.
- SQLite: Laravel membangun ulang tabel (`SQLiteGrammar::compileAlter`: `create __temp__`, `insert select`, `drop`, `rename`). Ketiga tabel ini **dirujuk tabel anak** (`enrollments`, `student_guardian`, `room_assignments`, `fee_overrides`, `invoices` → `students`; `invoice_items`, `payments` → `invoices`). `PRAGMA foreign_keys = OFF` dari `compileAlter` tidak berefek di dalam transaksi, tetapi review #1 membuktikan migration tetap lolos dengan data di dalam transaksi: pelanggaran sementara saat `drop`/`rename` pulih sebelum commit dan `PRAGMA foreign_key_check` kosong. (Koreksi DRAFT 1, yang keliru menyatakan migration pasti gagal.) Perilaku ini dikunci oleh test AC-4 (perilaku, bukan detail implementasi).
- Kolom generated `invoices.active_marker` dan kedua unique berbasis marker harus ikut terbawa (sama seperti pembuktian F009 pada `fee_rates`), dibuktikan AC-3.
- Tidak ada pembersihan data yatim: belum ada UI yang mengisi `applicant_id` (F005 tanpa form pendaftar, F006 tanpa tagihan pendaftar). Bila green-coder menemukan nilai `applicant_id` non-NULL di DB dev, laporkan; jangan hapus otomatis.
- Pembuktian otomatis di SQLite: AC-4. Green-coder tetap boleh mencoba `migrate:rollback --step=3` lalu `migrate` di DB dev berisi data dan mencatat hasilnya. MySQL/Postgres tidak boleh diklaim terverifikasi bila tidak dijalankan.

## Action class

Semua Action melempar `ValidationException::withMessages([$field => __("pondok.psb.errors.{$reason}")])` (pola F009). Kecuali disebut lain, Action bagian publik membaca data **tanpa cakupan jenjang** (`withoutUnitScope()`/`withoutGlobalScopes()` eksplisit), karena admin yang sedang login di browser yang sama tidak boleh mengubah hasil penomoran, kuota, atau pencarian. Tanggal "hari ini" = `now('Asia/Jakarta')`.

### `App\Actions\Psb\SubmitApplication`

`normalize(array $data, ?array $only = null): array` (public, dipakai juga oleh langkah wizard) lalu `handle(array $data): array{applicant: Applicant, token: string}`.

`normalize` (gagal pertama per field dilempar sekaligus sebagai satu `ValidationException` berisi semua field yang gagal di subset `$only`):

| Field | Normalisasi | Aturan → reason |
|---|---|---|
| `unit_id` | int | harus ada → `unit_not_found` |
| `admission_track_id` | int | harus ada → `track_not_found`; aktif → `track_inactive` |
| `admission_wave_id` | int | harus ada → `wave_not_found` |
| `name` | trim, spasi ganda dirapikan | 1–255 → `applicant_name_invalid` |
| `gender` | | `L`/`P` → `gender_invalid` |
| `birth_place` | trim | 1–100 → `birth_place_invalid` |
| `birth_date` | `Y-m-d` ketat | valid dan sebelum hari ini → `birth_date_invalid` |
| `nisn` | trim, kosong → NULL | tepat 10 digit → `nisn_invalid` |
| `previous_school` | trim | 1–255 → `previous_school_invalid` |
| `guardian_name` | trim | 1–255 → `guardian_name_invalid` |
| `guardian_phone` | buang spasi, `-`, `.`, `(`, `)`; awalan `+62`/`62` → `0` | `/^08\d{7,12}$/` → `phone_invalid` |
| `email` | trim, huruf kecil, kosong → NULL | `FILTER_VALIDATE_EMAIL`, ≤255 → `email_invalid` |
| `consent` | bool | wajib `true` → `consent_required` |

`handle` (setelah `normalize` semua field). **Loop percobaan di luar transaksi** (maksimal `MAX_ATTEMPTS = 3`); setiap percobaan adalah satu `DB::transaction` baru:
1. **Kunci diambil paling awal**, sebelum query lain di transaksi itu: `AcademicYear::lockForUpdate()->find($wave->academic_year_id)` (menyerialkan semua gelombang yang berbagi penghitung `YYYY`, karena nomor per tahun ajaran tujuan), lalu `AdmissionWave::lockForUpdate()->find()`. Tidak ada query non-locking ke `applicants` sebelum kedua kunci ini (snapshot REPEATABLE READ MySQL baru terbentuk setelah kunci).
2. Gelombang harus `isAcceptingRegistrationsOn(today)` → `admission_wave_id`/`wave_not_accepting`; tidak penuh → `admission_wave_id`/`wave_full`.
3. Pendaftaran ganda: pendaftar tidak terhapus di gelombang yang sama dengan `LOWER(name)`, `birth_date`, dan `guardian_phone` sama → `name`/`duplicate_application`.
4. Nomor dari `GenerateApplicantNumber` (dibaca di dalam transaksi ini, setelah kunci); token dari `ResetApplicantToken::newToken()`; simpan `status = diajukan`, `access_token_hash = Hash::make(normalizeToken($token))`, `consented_at = now()` (waktu server, Asia/Jakarta; nilai `consent` dari input hanya boolean dan tidak pernah menentukan waktunya).

`UniqueConstraintViolationException` pada `registration_no` ditangkap **di luar** transaksi (transaksi sudah di-rollback; aman di Postgres yang membatalkan seluruh transaksi saat unique violation), lalu percobaan berikutnya dimulai dengan transaksi dan kunci baru. Setelah `MAX_ATTEMPTS` gagal → `Log::error` + `RuntimeException` (`pondok.psb.errors.number_conflict`), tanpa baris tersisa. `ValidationException` tidak diulang. Pola sama dengan F006 (`GenerateMonthlyInvoices::createInvoice`), tetapi dengan transaksi baru per percobaan.

Setelah commit: bila `Setting::moduleEnabled('keuangan')`, panggil `GenerateRegistrationInvoices::handle($applicant)`; kegagalan dicatat `Log::error` dan **tidak** membatalkan pendaftaran (panitia bisa membuat tagihannya dari panel). Kembalikan pendaftar dan token polos (format tampil `XXXX-XXXX-XXXX`).

Rate limiting, honeypot, dan waktu isi minimal **tidak** di Action (butuh request); ada di komponen Livewire.

### `App\Actions\Psb\GenerateApplicantNumber`

`handle(AdmissionWave $wave): string` = `PSB-{YYYY}-{NNNN}`, `YYYY` = tahun `starts_on` tahun ajaran gelombang (2027/2028 → `2027`), `NNNN` 4 digit urut per `YYYY`. Penghitung: `max(registration_no)` dengan prefiks itu dari `Applicant::withoutGlobalScopes()` (termasuk terhapus; nomor tidak pernah dipakai ulang). Lewat 9999 → `LogicException`.

### `App\Actions\Psb\GenerateRegistrationInvoices`

`handle(Applicant $applicant, ?User $by = null): array{created_invoices: int, existing_invoices: int, skipped: list<array{fee_type_id: int, reason: string}>}`

- Pendaftar harus berstatus `diajukan` atau `diverifikasi` → `applicant_closed`.
- Jenis biaya: aktif, `frequency = saat_daftar`, urut `priority`, `id`. `applies_to` diabaikan (pendaftar belum punya status mukim).
- Per jenis biaya: tarif `ResolveFeeRate::handle($type, $wave->academicYear, $applicant->unit_id, null, null, $wave->id)`; keringanan `ResolveFeeOverride::forApplicant($applicant->id, $type, $period)`; nominal `ApplyFeeOverride`. Hasil `null` → `skipped` reason `missing_rate` + `Log::warning` (registration_no, fee_type_code), dilewati.
- Item dikelompokkan per `billing_group`. Kelompok yang sudah punya tagihan aktif untuk pendaftar ini → `existing_invoices`, tidak diubah (snapshot). Selain itu satu `invoices` baru: `applicant_id`, `student_id` NULL, `academic_year_id` = tahun ajaran gelombang, `period` = awal bulan hari ini, `billing_group`, `total`, `status` lewat `syncStatus()` (total 0 → `lunas`), `due_on` = `closes_on` gelombang (atau hari ini bila sudah lewat), `generated_by` = `$by?->id`, `invoice_no` dari `GenerateInvoiceNumber::handle($period)`.
- Item: `description` = `__('pondok.applicant.invoice_item_description', ['fee_type' => ..., 'wave' => 'Gelombang 1 2027/2028'])`, `rate_source` `manual` bila tanpa tarif, selain itu `jenjang`.
- Per kelompok: **loop percobaan di luar transaksi** (maks. 3), setiap percobaan satu `DB::transaction` baru yang diawali `Applicant::withoutGlobalScopes()->lockForUpdate()->find($applicant->id)`, lalu cek ulang tagihan aktif kelompok itu (ada → `existing_invoices`, selesai), lalu `GenerateInvoiceNumber::handle($period)` dibaca di dalam transaksi itu dan tagihan + item disimpan. `UniqueConstraintViolationException` ditangkap di luar transaksi: bila tagihan aktif kelompok itu kini ada → `existing_invoices`; selain itu nomor tagihan bentrok (mis. generator bulanan berjalan bersamaan dengan prefiks bulan sama) → percobaan berikutnya; setelah 3 kali → `Log::error` + `RuntimeException` (`pondok.invoice.errors.number_conflict`). Log model `Invoice` dibiarkan (satu activity `created` per tagihan).

### `App\Actions\Psb\CheckApplicantCredentials`

`handle(string $registrationNo, string $token): ?Applicant`. Nomor di-trim dan huruf besar; token dinormalisasi (`normalizeToken`: huruf besar, buang spasi dan `-`). Cari `Applicant::withoutGlobalScopes()->whereNull('deleted_at')` per nomor. `Hash::check` **selalu** dijalankan: bila nomor tidak ada, terhadap hash dummy konstan (`Hash::make` sekali per proses, disimpan statis) agar waktu respons tidak membocorkan nomor yang valid. Tidak ketemu atau token salah → `null` (pemanggil memberi pesan yang sama untuk keduanya).

### `App\Actions\Psb\ResetApplicantToken`

- `static newToken(): string` = 12 karakter acak (`random_int`) dari alfabet `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (tanpa I, O, 0, 1), dikelompokkan `XXXX-XXXX-XXXX`.
- `static normalizeToken(string $token): string`.
- `handle(Applicant $applicant, User $by): string`: hash baru disimpan tanpa log model, lalu satu activity manual `psb`, event `token_reset`, causer `$by`, subject pendaftar, properti **tanpa** token. Mengembalikan token polos (ditampilkan sekali di panel). Sesi publik lama otomatis tidak berlaku (sidik token berubah).

### `App\Actions\Psb\UploadApplicantDocument`

`handle(Applicant $applicant, AdmissionRequirement $requirement, UploadedFile $file): ApplicantDocument`

Urutan validasi (tanpa perubahan DB/disk bila gagal):
1. Status pendaftar `diajukan` → `uploads_closed`.
2. `registrationFeeSettled()` → `registration_fee_unpaid`.
3. Syarat termasuk `forUnit($applicant->unit_id)` → `requirement_not_applicable`.
4. Berkas lama untuk syarat itu berstatus `valid` → `document_already_valid`.
5. MIME dari isi file (`getMimeType()`) ∈ `application/pdf`, `image/jpeg`, `image/png` → `file` / `file_type_invalid`.
6. Ukuran ≤ `MAX_KB = 2048` → `file` / `file_too_large`.

Simpan file baru dulu ke disk `private` (`psb/applicants/{id}/{uuid}.{pdf|jpg|png}`, ekstensi dari MIME, bukan dari nama klien). Lalu dalam transaksi `updateOrCreate` per (`applicant_id`, `admission_requirement_id`): `file_path`, `original_name` (basename, dipotong 255), `mime_type`, `size_kb` (`ceil(bytes/1024)`), `status = menunggu`, `note`, `verified_by`, `verified_at` = NULL. Gagal transaksi → file baru dihapus. Setelah commit, file lama dihapus bila path berbeda.

### `App\Actions\Psb\VerifyApplicantDocument`

`handle(ApplicantDocument $document, User $by, string $status, ?string $note = null): ApplicantDocument`
- `$status` ∈ `valid`, `ditolak` → `status`/`document_status_invalid`.
- `ditolak` wajib catatan 1–500 karakter (trim) → `note`/`note_required`; `valid` mengosongkan catatan.
- Pendaftar berstatus `diajukan` atau `diverifikasi` → `applicant_closed`.
- Isi `verified_by`, `verified_at = now()`. Boleh mengubah keputusan sebelumnya. Activity `psb` otomatis dari model.

### `App\Actions\Psb\DeleteApplicant`

`handle(Applicant $applicant, User $by): void` — untuk pendaftaran spam/ganda.
- Ada tagihan pendaftar dengan `paid_total > 0` (status apa pun selain `batal`) → `applicant_has_payments`.
- Sudah punya santri (`student()` ada) → `applicant_converted`.
- Dalam transaksi: setiap tagihan aktif dibatalkan lewat `CancelInvoice::handle($invoice, $by, __('pondok.applicant.cancel_reason_deleted'))`, lalu soft delete. Berkas dan nomor tetap (nomor tidak dipakai ulang; kuota terbebas).

### Perubahan Action yang sudah ada

- `SaveFeeType`: setelah validasi yang ada, `billing_group` hanya boleh dipakai bersama jenis biaya dengan "kelas frekuensi" sama: kelas `saat_daftar`, kelas `saat_diterima`, dan kelas lain (`bulanan`, `tahunan`, `manual`). Ada jenis biaya lain (aktif maupun tidak, kecuali dirinya) dengan grup sama dan kelas berbeda → `billing_group`/`billing_group_frequency_conflict` (`pondok.keuangan.errors`). Alasan: setelah konversi (F012) tagihan PSB mendapat `student_id` dan tidak boleh bertabrakan dengan unique/generator bulanan; tagihan `saat_diterima` (F011) tidak boleh terhalang unique (`applicant_id`, `billing_group`, `active_marker`) milik tagihan formulir.
- `SaveAdmissionRequirement` (F009): saat edit, syarat yang sudah punya `applicant_documents` tidak boleh mengganti `unit_id` atau `document_type_id` → `document_type_id`/`requirement_in_use`. Nama, wajib/opsional, dan urutan tetap bisa diubah.
- `SaveFeeOverride` (F005): `applicant_id` terisi harus ada di `Applicant::query()` (cakupan jenjang user berlaku, tidak terhapus) → `applicant_id`/`applicant_not_found` (`pondok.keuangan.errors`).
- `Invoice::applyUnitScope` dan `Payment::applyUnitScope`: tagihan terlihat bila `student_id` ∈ santri jenjang dalam cakupan **atau** (`student_id` NULL dan `applicant_id` ∈ pendaftar jenjang dalam cakupan, termasuk terhapus). Perbarui PHPDoc kelas `Invoice` ("tidak terlihat oleh user berjenjang" dihapus).

## UI (Filament / Blade)

Rujukan design guide: §0 checklist; §2 token warna (tanpa `dark:` di portal); §3 (Instrument Sans, `tabular-nums` untuk nomor pendaftaran, token, uang; ikon Heroicon Outlined); §4 (badge status); §5 (`Rp 250.000` lewat `Payment::formatRupiah`, tanggal `15 Okt 2026`, telepon tanpa format); §6 portal/PSB (mobile-first, `max-w-2xl`, wizard, label di atas field, tombol lebar penuh di mobile, `x-ui.*`); §7 state; §8 konfirmasi; §9 sapaan "Anda"; §10 aksesibilitas; §13 alur anti-ui-slop, kontrak desain di bawah.

### Aset dan layout publik

- Belum ada layout portal (F017 belum dikerjakan). F010 membuat layout minimal `resources/views/layouts/public.blade.php` (nama sekolah dari `Setting::get('school.name', config('app.name'))`, `@vite(['resources/css/app.css'])`, `@livewireStyles/@livewireScripts` bila diperlukan Livewire 4, satu `h1` per halaman, `lang="id"`), yang nanti diperluas F017.
- Komponen anonim baru di `resources/views/components/ui/`: `button`, `badge` (menerima `color` semantik), `card`, `field` (label + error + hint), `alert`, `steps` (indikator wizard). Hanya kelas token (`primary-*`, `gray-*`, `success-*`, …).
- Tailwind di-build saat rilis (`npm run build`), tanpa Node saat runtime; JS Livewire dilayani Livewire sendiri, Alpine bawaan Livewire dipakai hanya untuk tombol salin token. Bila ada kelas Tailwind di PHP (`app/Livewire`), tambahkan `@source '../../app/Livewire/**/*.php'` di `app.css`.
- **Test tidak bergantung pada manifest Vite:** semua test HTTP dan Livewire halaman publik memanggil `$this->withoutVite()` (di `beforeEach` file test publik), sehingga lulus tanpa `public/build`. Orkestrator menjalankan `npm run build` sekali untuk cek visual. `public/build` di-gitignore, jadi **paket rilis F019 wajib memuat `public/build`**; tanpa itu `/psb` error 500 (`ViteManifestNotFoundException`). Dicatat untuk F019.
- `config/livewire.php` dipublikasikan dengan `temporary_file_upload.rules = ['required', 'file', 'max:2048', 'mimes:pdf,jpg,jpeg,png']` (endpoint unggah sementara Livewire publik dan tidak terikat sesi `/psb/status`); `temporary_file_upload.disk` tetap bawaan (`local`, folder `livewire-tmp`). Nilai lain konfigurasi dibiarkan bawaan.

### Route publik (`routes/web.php`)

Grup `prefix('psb')->name('psb.')->middleware(EnsureModuleIsEnabled::class.':psb')`:
- `GET /psb` → komponen `App\Livewire\Psb\RegistrationForm` (`psb.register`).
- `GET /psb/status` → komponen `App\Livewire\Psb\ApplicationStatus` (`psb.status`).
- `GET /psb/berkas/{applicantDocument}` → controller `App\Http\Controllers\Psb\DownloadOwnApplicantDocument` (`psb.document`).

### Kontrak desain — wizard pendaftaran (`/psb`)

- Pengguna & tujuan: calon wali di HP Android; mengirim pendaftaran dan menyimpan nomor + token.
- Aksi utama: "Simpan & lanjut" per langkah, lalu "Kirim pendaftaran". Sekunder: "Kembali".
- Urutan informasi: judul + gelombang yang dibuka (label accent "Pendaftaran dibuka", satu-satunya accent) → indikator langkah → field langkah aktif → tombol.
- Langkah: (1) Pilihan pendaftaran: jenjang, jalur (syarat jalur `terms` tampil setelah dipilih), gelombang (otomatis terpilih bila hanya satu; bila lebih dari satu, radio "Gelombang 1 — 2027/2028" tanpa pilihan bawaan). (2) Data calon santri: nama, jenis kelamin, tempat lahir, tanggal lahir, NISN (opsional), sekolah asal. (3) Data wali: nama wali, nomor HP/WhatsApp, email (opsional). (4) Periksa & kirim: ringkasan, daftar berkas yang perlu disiapkan (`forUnit`, wajib/opsional), kotak persetujuan pemrosesan data pribadi untuk PSB, tombol kirim.
- State: modul mati (403 middleware); tidak ada gelombang terbuka (kartu "Pendaftaran belum dibuka" + tautan cek status, tanpa form); error field (per langkah, tetap di langkah itu); gagal domain (gelombang tutup/penuh, ganda, rate limit, spam) sebagai `x-ui.alert` danger; memuat (`wire:loading`, tombol disabled + "Menyimpan…"); berhasil.
- Layar berhasil: nomor pendaftaran dan token besar (`tabular-nums`), peringatan "Token hanya ditampilkan sekali. Catat atau simpan tangkapan layar halaman ini.", tombol "Salin token", total biaya formulir (dari tagihan yang dibuat, atau kalimat "Tidak ada biaya formulir") dan petunjuk bayar ke panitia dengan menyebut nomor pendaftaran, tombol "Cek status pendaftaran" ke `/psb/status`. Muat ulang halaman kembali ke langkah 1 (token tidak tampil lagi).
- 360px: satu kolom, tombol `w-full sm:w-auto`, target sentuh ≥ 44px, tanpa scroll horizontal.
- Anti-spam: field honeypot `website` (visually hidden, `tabindex="-1"`, `autocomplete="off"`, `aria-hidden`), properti terkunci `startedAt` saat mount; kirim < 3 detik setelah mount atau honeypot terisi → pesan umum `spam_rejected`, tidak ada data. Rate limit `psb-register:{ip}` menghitung **setiap percobaan kirim** di langkah 4 (berhasil, gagal validasi/domain, honeypot, terlalu cepat), maksimal 10 per jam per IP; percobaan ke-11 → `too_many_registrations` tanpa memanggil Action. Navigasi antarlangkah tidak dihitung (tanpa penulisan DB). IP dari `request()->ip()`; proksi tepercaya diatur lewat env (lihat Permission & cakupan → Proksi).

### Kontrak desain — cek status (`/psb/status`)

- Pengguna & tujuan: pendaftar memantau status, tagihan formulir, dan mengunggah berkas.
- Tanpa sesi: form nomor pendaftaran + token, tombol "Lihat status". Gagal → satu pesan umum `credentials_invalid` (tidak membedakan nomor tidak ada vs token salah). Dua limiter, keduanya dicek sebelum `CheckApplicantCredentials`: `psb-status:{ip}` 5 percobaan gagal per menit per IP, dan `psb-status-no:{nomor ternormalisasi}` 10 percobaan gagal per jam per nomor (melawan tebakan terdistribusi). Percobaan gagal menambah keduanya; berhasil membersihkan limiter IP saja. Terkena salah satu → `too_many_attempts` dengan sisa detik.
- Berhasil: `session()->regenerate()`, simpan `psb.applicant_id` dan `psb.token_fingerprint`. Setiap render, pendaftar dimuat ulang tanpa cakupan; pendaftar terhapus atau sidik berbeda → sesi dilupakan dan form tampil lagi.
- Urutan informasi: (1) kartu identitas: nama, nomor, jenjang, jalur, gelombang, badge status + kalimat penjelasan status (`pondok.psb_public.status_help.*`); (2) tagihan pendaftaran: per tagihan aktif nomor tagihan, total, terbayar, sisa, badge status tagihan, petunjuk bayar; (3) berkas: daftar syarat (`requirements()`), label Wajib/Opsional, badge status berkas atau "Belum diunggah", catatan penolakan, tautan "Unduh" ke berkas sendiri, input unggah per syarat (PDF/JPG/PNG, maks 2 MB) bila `acceptsUploads()` dan berkas belum `valid`; bila belum lunas → `x-ui.alert` info "Unggah berkas dibuka setelah biaya formulir lunas."; bila status bukan `diajukan` → "Unggah berkas sudah ditutup."; (4) tombol sekunder "Keluar".
- Komponen tidak menyimpan model `Applicant` di properti publik (rehydrate Livewire akan memakai global scope user login); pendaftar dibaca dari sesi lewat `#[Computed]` dengan `Applicant::withoutGlobalScopes()`. Properti lain yang tidak boleh diubah klien (`startedAt`, token hasil kirim) memakai `#[Locked]`.
- Unggah: `WithFileUploads`, aturan Livewire `file|max:2048|mimes:pdf,jpg,jpeg,png` sebagai filter awal, lalu `UploadApplicantDocument` (sumber kebenaran). Berhasil → notifikasi/alert success "Berkas Kartu Keluarga berhasil diunggah dan menunggu verifikasi."
- 360px sama seperti wizard.

### Route unduh berkas

- Publik `psb.document` (`/psb/berkas/{document}`, `whereNumber('document')`, **tanpa** route model binding implisit): controller memuat `ApplicantDocument::withoutGlobalScopes()->find($id)` beserta pendaftar `withoutGlobalScopes()`, sehingga admin bercakupan yang login di browser sama tidak terpengaruh. Semua kasus tidak berhak dijawab **404 seragam**: id tidak ada, tanpa sesi, sesi pendaftar lain, sidik token lama, pendaftar terhapus, file hilang.
- Admin: di `AdminPanelProvider::authenticatedRoutes`, grup `EnsureModuleIsEnabled:psb`: `GET applicant-documents/{applicantDocument}` → `App\Http\Controllers\Psb\DownloadApplicantDocument` (nama `psb.applicant-document`). Binding memakai global scope (di luar cakupan → 404), lalu `Gate::authorize('view', $document->applicant)`.
- Admin tetap 403 (permission) / 404 (cakupan atau file hilang), karena pengguna panel sudah terautentikasi.
- Keduanya: `Storage::disk('private')->response($path, $original_name, ['Content-Type' => mime_type, 'X-Content-Type-Options' => 'nosniff'], 'inline')`; file hilang → 404.

### `ApplicantResource` — "Pendaftar" (`/admin/applicants`, grup PSB, sort 0, ikon `Heroicon::OutlinedUserPlus`)

`BelongsToModule` (`psb`), `ShowsActionErrorsOnForm`, `$hasTitleCaseModelLabel = false`. Tanpa Create dan Edit. Halaman `ListApplicants` dan `ViewApplicant`.

- Tabel: `registration_no` (searchable, `tabular-nums`), `name` (searchable), `unit.name`, `admissionWave.name` (deskripsi tahun ajaran), `admissionTrack.name` (toggleable), `guardian_phone` (searchable, toggleable tersembunyi), `created_at` "Tanggal daftar" `j M Y, H.i`, `status` badge (`statusLabel`/`statusColor`). Default sort `created_at` desc, `id` desc. Eager-load `unit`, `admissionWave.academicYear`, `admissionTrack`. Filter: gelombang, jenjang (`Unit::accessible()`), status, jalur. Empty state ikon `UserPlus`, `pondok.applicant.empty`.
- Aksi baris: `ViewAction`; `ActionGroup` berisi `deleteAction()`.
- `ViewApplicant` infolist (`Section` berjudul): "Pendaftaran" (nomor, gelombang + tahun ajaran, jalur, jenjang, status, tanggal daftar, waktu persetujuan data `consented_at` format `j M Y, H.i`), "Data calon santri", "Data wali", "Tagihan pendaftaran" (`RepeatableEntry`: nomor tagihan sebagai tautan ke `InvoiceResource` bila user boleh `view` tagihan, total, terbayar, badge status; kosong → `pondok.applicant.no_invoices`), "Kelengkapan berkas" (syarat wajib yang belum diunggah atau belum valid; kosong → "Semua berkas wajib sudah valid.").
- Relation manager `DocumentsRelationManager` (`documents`): kolom syarat, `original_name`, `size_kb` (KB), `updated_at` "Diunggah", status badge, catatan. Aksi: "Unduh" (url route admin, tab baru, ikon `ArrowDownTray`), "Tandai valid" dan "Tolak berkas" (form `note` wajib) memanggil `VerifyApplicantDocument`, tampil bila `verify-document:applicant`. Tanpa create/edit/delete. Empty state `pondok.applicant_document.empty`.
- Aksi header `ViewApplicant`:
  - `generateInvoicesAction()` "Buat tagihan pendaftaran": tampil bila modul keuangan aktif, permission `generate-invoices:applicant`, status `diajukan`/`diverifikasi`, dan belum ada tagihan pendaftaran aktif. `requiresConfirmation()`. Notifikasi menyebut jumlah tagihan yang dibuat atau "Tarif formulir belum diatur" bila semua dilewati.
  - `resetTokenAction()` "Atur ulang token": `requiresConfirmation()` dengan deskripsi akibat ("Token lama tidak bisa dipakai lagi..."), lalu notifikasi persistent berisi token baru (sekali tampil).
  - `deleteAction()` "Hapus pendaftar": `danger`, `requiresConfirmation()` dengan deskripsi akibat ("Tagihan yang belum dibayar ikut dibatalkan..."), memanggil `DeleteApplicant`, redirect ke daftar.

### Perubahan resource lain

- `AdmissionRequirementResource` (F009): `withExists('applicantDocuments')`; `DeleteAction` tersembunyi bila ada; field `unit_id` dan `document_type_id` disabled pada edit bila ada (Action tetap menolak).
- `UnitResource`: penjaga hapus ditambah `applicants`.
- `InvoiceResource`: eager-load `applicant`; kolom pengenal menampilkan NIS santri atau nomor pendaftaran, kolom nama menampilkan nama santri atau nama pendaftar; pencarian mencakup `applicant.registration_no` dan `applicant.name`; filter jenjang juga mencocokkan tagihan pendaftar lewat `applicant.unit_id`; infolist detail menampilkan "Pendaftar: PSB-2027-0001 — Nama" bila tanpa santri.
- `PaymentResource`: pola yang sama di tabel dan infolist.
- `ShowPaymentReceipt` + `keuangan/receipt.blade.php`: tagihan pendaftar menampilkan nomor pendaftaran dan nama pendaftar; "Diterima dari" = `guardian_name`.

### Teks `lang/id/pondok.php` (kunci baru)

`applicant.{label, plural, registration_no, consented_at, name, nisn, gender, birth_place, birth_date, previous_school, guardian_name, guardian_phone, email, unit, wave, track, status, registered_at, statuses.{draft,diajukan,diverifikasi,diterima,cadangan,ditolak}, section_registration, section_student, section_guardian, section_invoices, section_completeness, no_invoices, completeness_ok, empty, invoice_item_description, cancel_reason_deleted, generate_invoices, generate_invoices_description, invoices_generated, invoices_none_generated, reset_token, reset_token_description, token_reset_notice, delete, delete_description, deleted}`; `applicant_document.{label, plural, requirement, original_name, size_kb, uploaded_at, status, note, statuses.{menunggu,valid,ditolak}, download, mark_valid, reject, reject_note, empty, not_uploaded}`; `psb_public.{title, opens_badge, closed_title, closed_body, steps.{choice,student,guardian,review}, next, back, submit, saving, consent, prepare_documents, required, optional, success_title, token_warning, copy_token, copied, fee_total, no_fee, payment_hint, check_status, status_title, login_submit, logout, status_help.{...6 status}, invoices_title, documents_title, uploads_locked_unpaid, uploads_closed, upload, upload_hint, uploaded}`; `psb.errors.{unit_not_found (sudah ada dari F009), track_not_found, track_inactive, wave_not_found, wave_not_accepting, wave_full, applicant_name_invalid, gender_invalid, birth_place_invalid, birth_date_invalid, nisn_invalid, previous_school_invalid, guardian_name_invalid, phone_invalid, email_invalid, consent_required, duplicate_application, spam_rejected, too_many_registrations, too_many_attempts, credentials_invalid, applicant_closed, uploads_closed, registration_fee_unpaid, requirement_not_applicable, document_already_valid, file_type_invalid, file_too_large, document_status_invalid, note_required, applicant_has_payments, applicant_converted, requirement_in_use, number_conflict}`; `keuangan.errors.{billing_group_frequency_conflict, applicant_not_found}`; `invoice.applicant_owner`, `payment.applicant_owner`, `receipt.registration_no`. Pesan error berupa kalimat yang menyebut cara memperbaiki, sapaan "Anda" untuk pesan publik (§9).

### Design guide (`docs/design-guide.md`, commit yang sama)

- §4: baris "PSB | diajukan | gray" (memperjelas "terdaftar"), "PSB | diverifikasi | info", dan domain baru "Berkas PSB | menunggu `warning`, valid `success`, ditolak `danger`".
- §3: konsep ikon "Pendaftar → `UserPlus`", "Unggah berkas → `ArrowUpTray`", "Unduh → `ArrowDownTray`".

## Permission & cakupan

### Permission baru

`RolePermissionSeeder::RESOURCE_MODELS` ditambah `Applicant`; `EXTRA_RESOURCE_ACTIONS['Applicant'] = ['verifyDocument', 'resetToken', 'generateInvoices']` → 9 permission (`view-any|view|create|update|delete|delete-any|verify-document|reset-token|generate-invoices` `:applicant`). `ApplicantPolicy` dibuat `shield:generate`, lalu ditambah method `verifyDocument`, `resetToken`, `generateInvoices` (pola `InvoicePolicy::generate/cancel`). `ApplicantDocument` tidak punya policy sendiri; akses lewat policy `Applicant`.

### Pembagian ke peran

- `admin-psb`: permission F009 + `view-any:applicant`, `view:applicant`, `delete:applicant`, `verify-document:applicant`, `reset-token:applicant`, `generate-invoices:applicant`. Tanpa `create`, `update`, `delete-any`.
- `super-admin`: semua. Peran lain tidak berubah. Pembayaran formulir dicatat bendahara lewat `InvoiceResource`/`RecordPayment` yang sudah ada.

### Cakupan jenjang

- `Applicant` dan `ApplicantDocument` memakai global scope (`unit_user`). `Invoice`/`Payment` ikut melihat tagihan pendaftar jenjang dalam cakupan.
- Bagian publik (tanpa login) tidak disaring; Action publik tetap memakai query tanpa scope agar admin yang login di browser sama tidak memengaruhi hasil.

### Proksi (IP untuk rate limit)

`bootstrap/app.php` memanggil `$middleware->trustProxies(at: ...)` dengan nilai dari `config('pondok.trusted_proxies')` (env `TRUSTED_PROXIES`, daftar dipisah koma atau `*`). Default kosong = tidak ada proksi tepercaya, sehingga header `X-Forwarded-For` diabaikan (aman). Sekolah di balik Cloudflare/reverse proxy cPanel mengisi env ini; dicatat untuk panduan instalasi F019. Tanpa dependensi baru.

### Saklar modul

- `modules.psb.enabled`: middleware pada route publik dan route unduh admin (403), `BelongsToModule` pada `ApplicantResource`.
- `modules.keuangan.enabled` mati: tagihan pendaftaran tidak dibuat saat daftar, aksi "Buat tagihan pendaftaran" tersembunyi; unggah berkas terbuka karena tidak ada tagihan.

## Kriteria penerimaan (bisa dites)

Fixture bersama `pendaftaranFixture()` (di atas `psbFixture()` F009): `UnitSeeder` (SD, SLTP, SLTA); `DocumentTypeSeeder`; `AdmissionTrackSeeder`; tahun ajaran `Y2026` (2026-07-01..2027-06-30, aktif) dan `Y2027` (2027-07-01..2028-06-30); gelombang `W1` (`Y2027`, "Gelombang 1", 2026-10-01..2026-12-31, terbuka, kuota NULL); waktu `2026-10-15 10:00` Asia/Jakarta (`travelTo`); jenis biaya `FORMULIR` (`saat_daftar`, grup `PSB_DAFTAR`, prioritas 10) dengan tarif SLTP berlaku `Y2027` Rp250.000; syarat KK (semua jenjang, wajib, sort 1), AKTA (semua jenjang, wajib, sort 2), RAPOR (SLTP, opsional, sort 3), IJAZAH (SD, wajib); modul `psb` dan `keuangan` aktif; `Storage::fake('private')` dan `Storage::fake('public')`. Data valid `pendaftaranData()`: SLTP, `REGULER`, `W1`, name " Ahmad  Fauzan ", `L`, "Bandung", "2014-05-12", nisn "0123456789", "SDIT Al-Fatih", wali "Budi Santoso", HP "+62 812-3456-7890", email "Budi@Example.com", consent true.

Skema & migrasi
- AC-1: `Schema::hasColumns` benar untuk `applicants` (semua kolom tabel di atas termasuk `deleted_at`) dan `applicant_documents` (termasuk `original_name`, `mime_type`, `size_kb`, tanpa `deleted_at`); `applicants.consented_at` ada dan nullable. `Applicant` memakai `SoftDeletes`, `ApplicantDocument` tidak.
- AC-2: Constraint: `registration_no` ganda → `UniqueConstraintViolationException`; dua berkas untuk pendaftar + syarat sama → `UniqueConstraintViolationException`; `Schema::getIndexes('applicants')` memuat index kolom tepat [`admission_wave_id`, `status`] dan [`created_at`]; `QueryException` saat menghapus gelombang, jalur, dan jenjang yang punya pendaftar, syarat yang punya berkas, serta `forceDelete()` pendaftar yang punya berkas atau tagihan. `getForeignKeys` kedua tabel memuat semua FK di Model data dengan `on_delete` `restrict` (case-insensitive). Enum di level DB (acceptance F010): insert lewat factory untuk keenam status `draft`, `diajukan`, `diverifikasi`, `diterima`, `cadangan`, `ditolak` berhasil dan terbaca kembali sama; `DB::table('applicants')->insert` dengan status `batal` → `QueryException` (check constraint SQLite, mode strict MySQL). Hal yang sama untuk `applicant_documents.status` (`menunggu`, `valid`, `ditolak` berhasil; `diganti` gagal).
- AC-3: Setelah migration FK: `getForeignKeys` `students`, `fee_overrides`, `invoices` memuat FK `applicant_id` → `applicants.id` `restrict`; insert dengan `applicant_id` 999999 di ketiganya → `QueryException`. Pelestarian: `students` tetap punya `deleted_at`, unique [`nis`], unique [`nisn`], index [`unit_id`, `status`], index [`applicant_id`], FK `unit_id`/`cohort_id` restrict; `fee_overrides` tetap punya index [`student_id`, `fee_type_id`, `valid_from`] dan [`applicant_id`], FK `student_id`, `fee_type_id`, `scholarship_id`, `approved_by` restrict; `invoices` tetap punya `active_marker` dengan `generation` tidak null, unique `invoices_student_period_group_active_unique` dan `invoices_applicant_group_active_unique` dengan kolom tepat seperti migration F006, unique [`invoice_no`], index [`status`, `period`], FK `student_id`, `academic_year_id`, `generated_by` restrict. FK tabel anak tetap menunjuk tabel asli: `invoice_items.invoice_id` dan `payments.invoice_id` → `invoices`; `enrollments.student_id`, `room_assignments.student_id`, `fee_overrides.student_id`, `invoices.student_id` → `students`. Perilaku: tagihan aktif kedua untuk (pendaftar, grup) → `UniqueConstraintViolationException`; tagihan `batal` + aktif boleh; `active_marker` 1 untuk aktif, NULL untuk `batal`.
- AC-4: Perilaku SQLite dengan data terisi (test dilewati bila driver bukan `sqlite`): buat santri + enrollment + penempatan kamar, keringanan, tagihan santri + item + pembayaran + alokasi, pendaftar + tagihan pendaftar + keringanan pendaftar, dan santri dengan `applicant_id` terisi. Jalankan `down()` ketiga migration FK (urutan terbalik, objek di-`require` dari path) lalu `up()` (urutan maju). Hasil: jumlah baris semua tabel tersebut sama dengan sebelum, nilai `invoices.active_marker` tetap (1 aktif, NULL batal), `DB::select('PRAGMA foreign_key_check')` kosong, dan assert struktur AC-3 tetap terpenuhi (index, unique, generated column, FK termasuk FK tabel anak).

Aturan keuangan & syarat berkas
- AC-5: `SaveFeeType`: jenis `saat_daftar` grup `BULANAN` saat ada jenis `bulanan` grup `BULANAN` → `billing_group`/`billing_group_frequency_conflict`; jenis `saat_diterima` grup `PSB_DAFTAR` → konflik; jenis `bulanan` grup `PSB_DAFTAR` → konflik; jenis `saat_daftar` kedua grup `PSB_DAFTAR` → berhasil; `tahunan` + `bulanan` berbagi `BULANAN` → berhasil (perilaku lama); jenis nonaktif ikut dihitung; edit `FORMULIR` menjadi `bulanan` saat masih ada `saat_daftar` lain di grupnya → konflik; edit jenis biaya satu-satunya di grupnya mengganti frekuensi → berhasil.
- AC-6: `SaveAdmissionRequirement`: syarat KK yang punya berkas → ganti `document_type_id` atau `unit_id` → `document_type_id`/`requirement_in_use`, data tidak berubah; ganti nama, `is_required`, `sort_order` → berhasil. `ManageAdmissionRequirements`: aksi `delete` tersembunyi untuk syarat yang punya berkas, tampil untuk syarat tanpa berkas. `ManageUnits`: `delete` tersembunyi untuk jenjang yang punya pendaftar.
- AC-7: `SaveFeeOverride` dengan `applicant_id` 999999 → `applicant_id`/`applicant_not_found`; pendaftar terhapus → `applicant_not_found`; pendaftar SLTP oleh bendahara bercakupan SD → `applicant_not_found`; pendaftar valid → tersimpan.

Gelombang & kuota
- AC-8: `W1` kuota 2: dua pendaftar `diajukan` → `isFull()` true dan `openForRegistration(today)` kosong; satu di antaranya di-soft-delete → tidak penuh; pendaftar berstatus `draft` tidak dihitung; perhitungan sama saat login sebagai user bercakupan SD (tanpa scope). Kuota NULL tidak pernah penuh.
- AC-9: `openForRegistration`: `W1` (`Y2027`) dan `W2` (`Y2028` = 2028-07-01..2029-06-30, "Gelombang Inden", 2026-10-01..2027-01-31) → urutan [`W1`, `W2`]; `is_open` false, di luar rentang tanggal, atau penuh tidak muncul.

Pengiriman pendaftaran (`SubmitApplication`)
- AC-10: Data valid (tanpa login) → satu pendaftar: `registration_no` "PSB-2027-0001", status `diajukan`, name "Ahmad Fauzan", `guardian_phone` "081234567890", email "budi@example.com", `birth_date` 2014-05-12, `consented_at` sama dengan waktu `travelTo` (2026-10-15 10:00 Asia/Jakarta); mengirim `consented_at` palsu di `$data` (mis. "2020-01-01 00:00:00") diabaikan; token cocok pola `/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/`; `Hash::check(normalizeToken($token), access_token_hash)` true; `access_token_hash` tidak sama dengan token; `$applicant->toArray()` tidak memuat `access_token_hash`. Activity `psb` `created` tanpa causer dan tanpa properti selain `status`.
- AC-11a: Bentrok nomor pendaftaran: container diberi `GenerateApplicantNumber` palsu yang pada panggilan pertama mengembalikan nomor yang sudah dipakai ("PSB-2027-0001" sudah ada), selanjutnya perilaku asli → pendaftaran berhasil sebagai "PSB-2027-0002", tepat satu pendaftar baru, nomor dipanggil 2 kali. Palsu yang selalu mengembalikan nomor terpakai → `RuntimeException` setelah 3 percobaan, tidak ada pendaftar baru, `Log::error` terpanggil. Bentrok tagihan: `GenerateInvoiceNumber` palsu yang pertama kali mengembalikan "INV-202610-00001" yang sudah dipakai tagihan santri → tagihan pendaftar tersimpan sebagai "INV-202610-00002" dengan satu item; selalu bentrok → pendaftar tetap tersimpan tanpa tagihan dan `Log::error` terpanggil (kegagalan tagihan tidak membatalkan pendaftaran); `GenerateRegistrationInvoices::handle()` langsung dengan palsu selalu bentrok → `RuntimeException`, tanpa tagihan setengah jadi.
- AC-11: Penomoran: pendaftaran kedua → "PSB-2027-0002"; pendaftar ke gelombang `Y2028` → "PSB-2028-0001"; setelah "PSB-2027-0003" di-soft-delete, berikutnya "PSB-2027-0004"; login sebagai `admin-psb` bercakupan SD lalu mengirim pendaftaran SLTP → nomor tetap lanjut dari semua jenjang dan pendaftar tersimpan.
- AC-12: Gagal tanpa baris baru (dataset field → reason): unit 999 → `unit_id`/`unit_not_found`; jalur 999 → `admission_track_id`/`track_not_found`; jalur nonaktif → `track_inactive`; gelombang 999 → `admission_wave_id`/`wave_not_found`; name "  " → `name`/`applicant_name_invalid`; gender "X" → `gender_invalid`; birth_place "" → `birth_place_invalid`; birth_date "2026-10-15" (hari ini), "12-05-2014", "2014-02-30" → `birth_date_invalid`; nisn "12345" atau "01234567ab" → `nisn_invalid`; previous_school "" → `previous_school_invalid`; guardian_name "" → `guardian_name_invalid`; HP "12345", "0712345678", "+1 555 1234" → `phone_invalid`; email "budi@" → `email_invalid`; consent false, "0", atau tidak ada → `consent_required` (tanpa baris, tanpa `consented_at`). `normalize($data, ['name', 'gender'])` hanya memeriksa dua field itu.
- AC-13: Gagal domain tanpa baris baru: `W1` `is_open` false atau `travelTo('2027-01-01')` → `admission_wave_id`/`wave_not_accepting`; kuota penuh → `wave_full`; pendaftar dengan nama (beda huruf besar-kecil), tanggal lahir, dan HP sama di `W1` → `name`/`duplicate_application`; data sama di gelombang lain atau duplikat yang sudah dihapus → berhasil.

Tagihan pendaftaran (`GenerateRegistrationInvoices`)
- AC-14: Setelah pendaftaran valid: satu `invoices` dengan `applicant_id` pendaftar, `student_id` NULL, `academic_year_id` `Y2027`, `period` 2026-10-01, `billing_group` "PSB_DAFTAR", `total` 250000, `paid_total` 0, status `belum`, `due_on` 2026-12-31, `generated_by` NULL, `invoice_no` "INV-202610-00001" (atau nomor berikutnya bila sudah ada "INV-202610-00007" → "INV-202610-00008"); satu item `FORMULIR` base 250000, discount 0, amount 250000, `rate_source` `jenjang`, `description` memuat nama jenis biaya dan "Gelombang 1".
- AC-15: Variasi: tarif khusus `W1` Rp200.000 di samping tarif umum → item 200000; dua jenis `saat_daftar` dalam grup sama → satu tagihan dua item, total jumlah keduanya; grup berbeda → dua tagihan; jenis `saat_daftar` nonaktif, `bulanan`, dan `saat_diterima` tidak ditagih; jenis tanpa tarif → tidak ada item, `skipped` berisi `missing_rate`, `Log::warning` terpanggil, pendaftar tetap tersimpan; keringanan pendaftar mode `nominal` 0 untuk `FORMULIR` lalu `handle()` pada pendaftar tanpa tagihan → item base 250000, discount 250000, amount 0, `fee_override_id` terisi, status tagihan `lunas`.
- AC-16: `handle()` dua kali → tetap satu tagihan aktif, hasil kedua `existing_invoices` 1; setelah tagihan dibatalkan (`CancelInvoice`) → `handle()` membuat tagihan baru bernomor baru; pendaftar `ditolak` → `applicant_closed`; modul keuangan mati saat mendaftar → pendaftar tersimpan tanpa tagihan.

Unggah berkas
- AC-17: `registrationFeeSettled()`: tanpa tagihan → true; tagihan pendaftaran `belum` atau `sebagian` → false; `lunas` → true; hanya tagihan `batal` → true; tagihan pendaftar lain yang itemnya `saat_diterima` dan belum dibayar tidak memengaruhi.
- AC-18: Formulir lunas, unggah PDF 100 KB untuk KK → satu `applicant_documents`: status `menunggu`, `original_name` "kk.pdf", `mime_type` "application/pdf", `size_kb` 100, `file_path` diawali `psb/applicants/{id}/`, berakhiran `.pdf`, dan tidak memuat "kk"; file ada di disk `private`; disk `public` kosong. JPG dan PNG juga diterima.
- AC-19: Unggah ulang: berkas KK `ditolak` (catatan, `verified_by`, `verified_at` terisi) → unggah baru → id baris sama, jumlah baris 1, status `menunggu`, `note`/`verified_by`/`verified_at` NULL, file lama hilang, file baru ada.
- AC-20: Gagal tanpa baris dan file baru (dataset): formulir belum lunas → `registration_fee_unpaid`; status `diverifikasi`, `diterima`, `cadangan`, `ditolak` → `uploads_closed`; syarat IJAZAH (SD) untuk pendaftar SLTP → `requirement_not_applicable`; berkas KK sudah `valid` → `document_already_valid`; file teks bernama `kk.pdf` → `file`/`file_type_invalid`; PDF 2049 KB → `file`/`file_too_large`.

Halaman publik
- AC-21: Tanpa `public/build` (test memakai `withoutVite`): `GET /psb` (tamu) → 200, memuat `__('pondok.psb_public.title')` dan "Gelombang 1"; modul PSB mati → 403 di `/psb`, `/psb/status`, dan `/psb/berkas/{id}`. Tanpa gelombang terbuka → 200 memuat `pondok.psb_public.closed_title` dan tidak memuat tombol `pondok.psb_public.next`.
- AC-22: Wizard (Livewire test, `travel(5)->seconds()` sebelum kirim): satu gelombang → `admission_wave_id` terisi otomatis; dua gelombang terbuka → kosong dan kedua label "Gelombang 1 — 2027/2028", "Gelombang Inden — 2028/2029" tampil. Langkah 1 tanpa jalur → error `admission_track_id`, tetap di langkah 1; langkah valid → langkah 2; "Kembali" mempertahankan isian; langkah 4 tanpa persetujuan → error `consent`, tidak ada pendaftar; kirim valid → pendaftar tersimpan dengan `consented_at` terisi dan layar berhasil memuat nomor "PSB-2027-0001", token (pola AC-10), "Rp 250.000", dan tautan `route('psb.status')`; komponen baru (render ulang) tidak memuat token itu.
- AC-23: Anti-spam: honeypot `website` terisi → tidak ada pendaftar, pesan `spam_rejected`; kirim tanpa `travel` (< 3 detik) → sama. Rate limit: sepuluh percobaan kirim dari IP sama dalam satu jam (campuran gagal validasi, honeypot, dan berhasil) → percobaan ke-11 dengan data valid → `too_many_registrations`, tidak ada pendaftar baru; navigasi "Simpan & lanjut" tidak menambah hitungan; setelah `travel(61)->minutes()` berhasil lagi. Dengan konfigurasi bawaan (tanpa `TRUSTED_PROXIES`), header `X-Forwarded-For` berbeda tidak memisahkan hitungan (kunci limiter tetap IP asal).
- AC-24: Cek status: nomor + token benar (token huruf kecil tanpa tanda hubung, nomor huruf kecil dengan spasi tepi juga diterima) → memuat nama, nomor, label status `Diajukan`, tagihan "Rp 250.000" dengan label status tagihan, dan daftar syarat KK, AKTA (Wajib), RAPOR (Opsional), tanpa IJAZAH; token salah, nomor tidak ada, atau pendaftar terhapus → pesan `credentials_invalid` yang sama dan tidak memuat nama.
- AC-25: Rate limit cek status: lima percobaan gagal dari IP sama, percobaan keenam dengan token benar → `too_many_attempts` dan data tidak tampil; setelah `travel(61)->seconds()` berhasil. Per nomor: sepuluh percobaan gagal untuk nomor sama dari IP berbeda (`REMOTE_ADDR` diganti tiap percobaan) → percobaan ke-11 dengan token benar dari IP baru → `too_many_attempts`. `CheckApplicantCredentials` dengan nomor yang tidak ada tetap memanggil `Hash::check` tepat satu kali (spy `Hash`), sama seperti nomor yang ada.
- AC-26: Sesi: setelah berhasil, komponen baru dalam sesi yang sama langsung menampilkan data tanpa form; aksi "Keluar" mengembalikan form; setelah `ResetApplicantToken` dijalankan, komponen baru dalam sesi lama menampilkan form lagi; token lama gagal, token baru berhasil.
- AC-27: Unggah lewat komponen: formulir belum lunas → input unggah tidak ada dan teks `uploads_locked_unpaid` tampil; setelah dibayar penuh lewat `RecordPayment` → unggah KK (`UploadedFile::fake()->create('kk.pdf', 100, 'application/pdf')`) → badge "Menunggu" tampil untuk KK; berkas `ditolak` menampilkan catatannya; berkas `valid` tidak punya input unggah.

Akses berkas ("Berkas hanya bisa diakses lewat token atau permission")
- AC-28: Route publik `psb.document`: pemilik sesi → 200, `Content-Type` sesuai `mime_type`, `Content-Disposition` inline, `X-Content-Type-Options: nosniff`; id tidak ada, tanpa sesi, sesi pendaftar lain, sesi dengan sidik token lama (setelah reset), pendaftar terhapus, dan file hilang dari disk → semuanya 404. User `admin-psb` bercakupan SD yang sedang login di sesi yang sama tetap bisa masuk `/psb/status` dengan kredensial pendaftar SLTP, melihat datanya, mengunggah, dan mengunduh berkasnya (200).
- AC-28a: `config('livewire.temporary_file_upload.rules')` memuat `max:2048` dan `mimes:pdf,jpg,jpeg,png`; unggah sementara lewat komponen `ApplicationStatus` dengan file 3 MB atau file `.exe` → error validasi pada properti unggah, tidak ada `applicant_documents`.
- AC-29: Route admin `psb.applicant-document`: `admin-psb` dan super-admin → 200; bendahara, admin-sekolah, kesantrian → 403; `admin-psb` bercakupan SD untuk berkas pendaftar SLTP → 404; tamu → redirect ke login panel; modul PSB mati → 403. Tidak ada file berkas di disk `public` dan path berkas tidak bisa diakses lewat route bawaan disk `local` (yang berbagi root `storage/app/private` dan `serve => true`): `GET /storage/{file_path}` tanpa tanda tangan → 403 atau 404, bukan 200.

Panel admin
- AC-30: `RolePermissionSeeder` membuat 9 permission `applicant`; `admin-psb` mendapat persis permission F009 ditambah `view-any:applicant`, `view:applicant`, `delete:applicant`, `verify-document:applicant`, `reset-token:applicant`, `generate-invoices:applicant`; dataset peran lain tidak berubah; test "creates every permission that a generated policy checks" tetap lulus dengan `ApplicantPolicy`.
- AC-31: `GET /admin/applicants` dan halaman view: `admin-psb`, super-admin → 200; bendahara, admin-sekolah, admin-konten, kesantrian, musyrif, ustadz-tahfidz, petugas-pos → 403. Modul PSB mati → 403 dan `/admin` (admin-psb, tetap 200) tidak memuat label jamak `pondok.applicant.plural` maupun tautan `/admin/applicants`.
- AC-32: `ListApplicants`: `admin-psb` bercakupan SLTP melihat pendaftar SLTP, tidak melihat SD; view pendaftar SD → 404; opsi filter jenjang hanya SLTP. Tanpa cakupan melihat semua. Pencarian "PSB-2027-0002" dan nama hanya menampilkan baris itu; filter status `diajukan` dan gelombang bekerja; urutan bawaan terbaru di atas; badge status per dataset 6 status memakai label `pondok.applicant.statuses.*` dan warna sesuai Model data (`statusColor()`); tabel kosong memuat `pondok.applicant.empty`.
- AC-33: `ViewApplicant` menampilkan nomor, nama, nama wali, tagihan (nomor tagihan, "Rp 250.000", label status), dan kelengkapan "Kartu Keluarga" + "Akta kelahiran" sebagai belum diunggah; setelah keduanya `valid` → `completeness_ok`. `DocumentsRelationManager`: "Tandai valid" → status `valid`, `verified_by` user, `verified_at` terisi; "Tolak berkas" tanpa catatan → error `note`, data tetap; dengan catatan → `ditolak` + catatan; kedua aksi tersembunyi untuk user yang hanya punya `view-any`/`view` `applicant`.
- AC-34: `VerifyApplicantDocument` langsung: status "hapus" → `document_status_invalid`; pendaftar `ditolak` → `applicant_closed`; `valid` setelah `ditolak` mengosongkan catatan; activity `psb` `updated` dengan `old.status` dan `attributes.status`.
- AC-35: "Atur ulang token" (konfirmasi) → notifikasi memuat token baru berpola AC-10; token lama gagal di `CheckApplicantCredentials`, token baru berhasil; tepat satu activity `psb` event `token_reset`, causer user, properti tidak memuat token lama maupun baru. Tersembunyi tanpa `reset-token:applicant`.
- AC-36: "Hapus pendaftar" (konfirmasi): pendaftar dengan tagihan `belum` → tagihan menjadi `batal` dengan activity alasan `cancel_reason_deleted`, pendaftar ter-soft-delete dan hilang dari daftar, cek status dengan kredensialnya gagal; pendaftar dengan pembayaran → error `applicant_has_payments`, tidak ada perubahan; nomor yang dihapus tidak dipakai ulang (AC-11).
- AC-37: "Buat tagihan pendaftaran": tersembunyi bila sudah ada tagihan pendaftaran aktif, modul keuangan mati, tanpa permission, atau status `ditolak`; tampil untuk pendaftar tanpa tagihan (tarif dibuat setelah pendaftaran) → tagihan dibuat dengan `generated_by` user dan aksi tersembunyi sesudahnya.

Integrasi keuangan
- AC-38: Cakupan tagihan: bendahara bercakupan SLTP melihat (query dan `ListInvoices`) tagihan pendaftar SLTP, tidak melihat tagihan pendaftar SLTA, dan pembayaran mengikuti aturan yang sama di `ListPayments`; bendahara tanpa cakupan dan konteks tanpa login melihat semuanya. Tagihan santri tetap mengikuti aturan F006.
- AC-39: `ListInvoices` menampilkan "PSB-2027-0001" dan nama pendaftar pada tagihan pendaftar, bisa dicari dengan nomor pendaftaran; filter jenjang SLTP memuat tagihan pendaftar SLTP; `ViewInvoice` dan `ViewPayment` menampilkan pemilik pendaftar; kuitansi pembayaran tagihan pendaftar memuat nomor pendaftaran, nama pendaftar, dan "Diterima dari" nama wali.

Teks
- AC-40: Setiap kunci baru di bagian UI (`pondok.applicant.*`, `pondok.applicant_document.*`, `pondok.psb_public.*`, `pondok.psb.errors.*` baru, `pondok.keuangan.errors.billing_group_frequency_conflict`/`applicant_not_found`) diterjemahkan, bukan kunci mentah (dataset).

## Rencana test

Konvensi `it('AC-n: ...')`. Helper baru di bagian terpisah akhir `tests/Pest.php` ("Helper pendaftaran PSB (F010)"; periksa `git diff` dulu karena F008/F009 menyunting file yang sama): `pendaftaranFixture()`, `pendaftaranData(array $overrides = [])`, `pendaftaranSubmit(array $overrides = []): array`, `pendaftaranApplicant(array $attributes = [], ?string $token = null): Applicant`, `pendaftaranPayRegistration(Applicant $applicant)`, `pendaftaranAssertRejected(Closure $callback, string $reason, ?string $field = null)`. Factory baru `ApplicantFactory` (state `status(string)`, `withToken(string)`, `forWave(AdmissionWave)`), `ApplicantDocumentFactory` (state `valid()`, `rejected(string $note)`), `FeeTypeFactory::onRegistration()`.

| AC | File | Nama test (usulan) |
|---|---|---|
| 1–4 | `tests/Feature/Database/PsbApplicantSchemaTest.php` | `creates applicant tables`, `enforces applicant constraints`, `adds applicant foreign keys and keeps indexes` (per tabel), `keeps child foreign keys`, `enforces status enums`, `rebuilds tables with data on sqlite` |
| 5 | `tests/Feature/Actions/Keuangan/SaveFeeTypeTest.php` (tambah) | `AC-5 (F010): separates psb billing groups` (dataset) |
| 6 | `tests/Feature/Actions/Psb/SaveAdmissionRequirementTest.php` (tambah), `tests/Feature/Filament/Resources/PsbReferencesTest.php` (tambah) | `AC-6 (F010): locks requirements in use`, `hides requirement and unit delete when referenced` |
| 7 | `tests/Feature/Actions/Keuangan/SaveFeeOverrideTest.php` (tambah) | `AC-7 (F010): validates the applicant` |
| 8, 9 | `tests/Feature/Models/AdmissionWaveQuotaTest.php` | `counts applicants against the quota`, `lists waves open for registration` |
| 10–13, 11a | `tests/Feature/Actions/Psb/SubmitApplicationTest.php` | `submits an application`, `retries on number conflicts`, `numbers applications per year`, `rejects invalid fields` (dataset), `rejects closed, full and duplicate applications` |
| 14–16 | `tests/Feature/Actions/Psb/GenerateRegistrationInvoicesTest.php` | `bills the registration fee`, `applies wave rates, groups and overrides`, `is idempotent` |
| 17–20 | `tests/Feature/Actions/Psb/UploadApplicantDocumentTest.php` | `knows when the registration fee is settled`, `stores a document privately`, `replaces a document`, `rejects uploads` (dataset) |
| 21–23 | `tests/Feature/Livewire/Psb/RegistrationFormTest.php` | `renders the registration page`, `walks through the wizard`, `rejects spam and rate limits` |
| 24–27 | `tests/Feature/Livewire/Psb/ApplicationStatusTest.php` | `shows the status with valid credentials`, `rate limits status checks`, `keeps and invalidates the session`, `uploads documents from the status page` |
| 28, 29 | `tests/Feature/Http/Psb/ApplicantDocumentDownloadTest.php` | `lets the applicant download own documents`, `hides documents with a uniform 404`, `works for a scoped admin session`, `lets permitted staff download documents` |
| 28a | `tests/Feature/Livewire/Psb/ApplicationStatusTest.php` (tambah) | `restricts livewire temporary uploads` |
| 30 | `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (ubah) | dataset `admin-psb`, `AC-30: creates the applicant permissions` |
| 31–33, 35–37 | `tests/Feature/Filament/Resources/Applicants/ApplicantResourceTest.php` | sesuai AC |
| 34 | `tests/Feature/Actions/Psb/VerifyApplicantDocumentTest.php` | `verifies documents`, `rejects invalid verification` |
| 38 | `tests/Feature/Models/InvoiceApplicantScopeTest.php` | `scopes applicant invoices and payments by unit` |
| 39 | `tests/Feature/Filament/Resources/Invoices/InvoiceResourceTest.php`, `.../Payments/PaymentResourceTest.php`, `tests/Feature/Http/Keuangan/PaymentReceiptTest.php` (tambah) | `shows applicant owners` |
| 40 | `tests/Feature/Filament/PsbAccessTest.php` (tambah) | `translates every applicant text key` (dataset) |

**Penyesuaian test lama (red-tester, mode RED).** FK baru membuat `applicant_id` fiktif gagal. Ganti dengan `Applicant::factory()->create()->id` tanpa melemahkan assert:
- `tests/Feature/Database/KeuanganConstraintsTest.php` baris ±129–131, ±197–213, ±331–334.
- `tests/Feature/Models/PaymentTest.php` ±85; `tests/Feature/Filament/Resources/Payments/PaymentResourceTest.php` ±92; `tests/Feature/Http/Keuangan/PaymentReceiptTest.php` ±139 (assert tampilan pemilik ikut AC-39 bila berubah).
- `tests/Feature/Filament/Resources/FeeOverrides/FeeOverrideResourceTest.php` ±66; `tests/Feature/Actions/Keuangan/SaveFeeOverrideTest.php` ±148–152, ±212, ±256–260; `tests/Feature/Actions/Keuangan/ResolveFeeOverrideTest.php` ±35–48 (dua pendaftar nyata menggantikan 77/78).
- `tests/Feature/Actions/Keuangan/FinanceReportScopeTest.php` ±42, `SummarizeInvoicesTest.php` ±146, `ListTopArrearsTest.php` ±80 (F008). Untuk `FinanceReportScopeTest`: maksud "user bercakupan SLTP tidak menghitung tagihan pendaftar" dipertahankan dengan pendaftar **SLTA**; perubahan perilaku untuk pendaftar SLTP diuji AC-38.
- `RolePermissionSeederTest` dataset `admin-psb`.

## Urutan implementasi

1. Migration + model + factory `applicants`, `applicant_documents`; relasi balik di `AdmissionWave`, `AdmissionTrack`, `Unit`, `AdmissionRequirement`, `Invoice`, `FeeOverride`, `Student`.
2. Tiga migration FK `applicant_id` (transaksi bawaan); penyesuaian test lama; AC-1–4 hijau (AC-4 dengan data terisi).
3. `SaveFeeType` (pemisahan grup), `SaveFeeOverride` (validasi pendaftar), `SaveAdmissionRequirement` (syarat dipakai).
4. Helper kuota gelombang (`countedApplicants`, `isFull`, `openForRegistration`).
5. `GenerateApplicantNumber`, `ResetApplicantToken` (token), `SubmitApplication`.
6. `GenerateRegistrationInvoices`; perluasan `Invoice::applyUnitScope`/`Payment::applyUnitScope`.
7. `CheckApplicantCredentials`, `UploadApplicantDocument`, `VerifyApplicantDocument`, `DeleteApplicant`.
8. `RolePermissionSeeder` + `ApplicantPolicy` (`shield:generate` + method tambahan).
9. `config/pondok.php` `trusted_proxies` + `trustProxies` di `bootstrap/app.php`; publikasi `config/livewire.php` dengan aturan unggah sementara.

**Gerbang:** langkah 1–9 (dan test AC yang tidak butuh UI: 1–20, 11a, 30, 34, 38 bagian query) hijau penuh sebelum langkah 10 dimulai. Bila konteks agent habis, titik pecah alami: setelah langkah 9 (F010a) dan setelah langkah 12 (F010b, halaman publik); panel admin (13–14) ikut salah satunya.

10. Teks `lang/id/pondok.php`; baris design guide §3/§4.
11. Layout publik + komponen `x-ui.*`; route publik; `RegistrationForm` (wizard, anti-spam, rate limit).
12. `ApplicationStatus` (sesi, tagihan, unggah) + controller unduh publik.
13. `ApplicantResource` (list, view, relation manager, aksi header) + controller unduh admin.
14. Perubahan `AdmissionRequirementResource`, `UnitResource`, `InvoiceResource`, `PaymentResource`, kuitansi.
15. Sinkronkan `docs/erd.md` (commit `docs(psb)`): kolom `applicants.consented_at` (timestamp, bukti persetujuan UU PDP), kolom tambahan `applicant_documents`, FK `applicant_id` kini ada, aturan pemisahan `billing_group` PSB, kuota dihitung tanpa `draft`/terhapus, format token, dan catatan "Biaya PSB": formulir lunas ditandai lewat pembayaran yang dicatat bendahara (`RecordPayment`), bukan oleh panitia (keputusan default #14, review #1 temuan 9).
16. Pint, test terkait, checklist design guide §0 (cek visual `https://pondok.test/psb` dan `/psb/status` di desktop dan 360px setelah orkestrator menjalankan `npm run build` sekali; bila belum dibangun, laporkan belum dicek). Laporan menyebut bahwa paket rilis F019 wajib memuat `public/build`.

## Risiko & pertanyaan terbuka

Setiap pertanyaan punya default yang dipakai bila user tidak memutuskan lain.

1. **Ukuran fitur.** 40 AC, dua halaman publik, satu resource, dan perombakan tiga tabel. *Diputuskan orkestrator (r3):* tetap satu fitur, 16 langkah micro-commit, dengan gerbang langkah 1–9 hijau sebelum halaman publik (lihat Urutan implementasi).
2. **Perombakan SQLite tabel yang punya anak.** Risiko utama (lihat Model data). *Diputuskan orkestrator (r3):* migration bertransaksi bawaan, dipecah per tabel; AC-3 (struktur) + AC-4 (rollback lalu migrate dengan data terisi di SQLite). MySQL/Postgres tidak diklaim terverifikasi tanpa dijalankan.
3. **Status `draft`.** Default: tidak dipakai alur publik (wizard tidak menyimpan apa pun sebelum dikirim, menghindari baris sampah dan data pribadi yang tak pernah dikirim). Enum tetap ada sesuai ERD dan kuota tidak menghitungnya.
4. **Pemilihan gelombang bila beberapa terbuka.** Default: hanya satu → otomatis; lebih dari satu (pasti lintas tahun ajaran karena F009 melarang irisan dalam satu tahun) → pendaftar memilih, berlabel tahun ajaran; gelombang penuh disembunyikan.
5. **Kuota.** Default: dihitung dari pendaftar tidak terhapus dengan status selain `draft` (termasuk yang nanti ditolak), dikunci dengan `lockForUpdate` (tahun ajaran lalu gelombang) di awal setiap percobaan transaksi. Menolak pendaftar tidak membuka kuota. Di SQLite `lockForUpdate` no-op, tetapi penulisan diserialkan.
6. **Format token.** Default: 12 karakter tanpa huruf/angka mirip, ditampilkan `XXXX-XXXX-XXXX` (~60 bit) + rate limit; disimpan `Hash::make`. Token hilang → panitia "Atur ulang token" dan menyampaikannya langsung ke wali.
7. **Pengiriman token lewat email.** Default: tidak (mail di cPanel sering belum diatur; WhatsApp = F021). Token hanya tampil di layar sekali.
8. **Batas rate limit.** Default: daftar 5 per jam per IP, cek status 5 percobaan gagal per menit per IP, waktu isi minimal 3 detik. Konstanta di kelas, bukan `settings`. *Revisi r3:* daftar = 10 percobaan kirim per jam per IP (semua percobaan dihitung, bukan hanya yang berhasil); cek status = 5 gagal per menit per IP + 10 gagal per jam per nomor. Banyak wali bisa berbagi IP (warnet, NAT operator); bila keluhan muncul, angkanya dinaikkan. IP benar di balik proksi butuh env `TRUSTED_PROXIES` (default kosong = aman; dicatat untuk panduan instalasi F019). Pesan `duplicate_application` membuka fakta bahwa kombinasi nama + tanggal lahir + HP sudah terdaftar di gelombang itu; diterima sebagai risiko rendah (butuh ketiga data pribadi itu sekaligus).
9. **Persetujuan data pribadi (UU PDP).** *Diputuskan orkestrator (r1):* kotak persetujuan wajib di langkah 4 dan waktunya disimpan di kolom `applicants.consented_at` (diisi server saat pendaftaran publik). ERD disinkronkan. Teks persetujuan (`pondok.psb_public.consent`) tidak diversikan; bila nanti perlu bukti versi teks, usulkan entri baru.
10. **Kolom tambahan `applicant_documents`** (`original_name`, `mime_type`, `size_kb`). Default: ditambahkan karena dibutuhkan `documents` (F012/F013) dan unduhan. Perlu sinkronisasi ERD.
11. **Batas berkas.** Default: PDF/JPG/PNG maksimal 2 MB (sesuai `upload_max_filesize` bawaan PHP di shared hosting), tanpa kompresi gambar.
12. **`billing_group` PSB.** Default: grup `saat_daftar`, `saat_diterima`, dan grup lain tidak boleh bercampur (AC-5). Data lama yang sudah bercampur tidak dimigrasi; baru ditolak saat disimpan ulang.
13. **`applies_to` untuk biaya pendaftaran.** Default: diabaikan (pendaftar belum mukim/nonmukim). F011 memutuskan sendiri untuk biaya `saat_diterima`.
14. **Pencatatan pembayaran formulir.** ERD: "Panitia bisa menandai lunas untuk pembayaran tunai". Default: dicatat bendahara lewat `RecordPayment` di `InvoiceResource`; `admin-psb` **tidak** diberi permission keuangan (pemisahan tugas). Sekolah yang ingin panitia mencatat bisa memberi `view-any:invoice`, `view:invoice`, `create:payment` lewat Shield.
15. **Tagihan pendaftar di laporan F008.** Setelah perluasan scope, bendahara bercakupan melihat tagihan pendaftar jenjangnya di total laporan tanpa filter, tetapi filter jenjang laporan (`FinanceReportFilter`) tetap hanya menghitung tagihan santri (keputusan F008). Default: dibiarkan; usulkan entri baru bila perlu diselaraskan.
16. **Verifikasi berkas vs F011.** Deskripsi F011 menyebut "Verifikasi berkas". Default: F010 = verifikasi per berkas (valid/ditolak); F011 = keputusan status pendaftar (termasuk `diverifikasi`). Perlu catatan di entri F011.
17. **Hapus pendaftar.** Default: soft delete untuk spam/ganda, tagihan belum dibayar dibatalkan, ditolak bila sudah ada pembayaran. Tanpa restore di F010.
18. **Layout publik sebelum F017.** Default: F010 membuat layout dan komponen `x-ui.*` minimal; F017 memperluasnya, bukan membuat ulang. Perlu catatan di entri F017.
19. **Tes seleksi.** ERD/PRD menyebut "formulir lunas membuka ... tes", tetapi tidak ada tabel tes. Default: di luar lingkup; status page tidak menampilkan jadwal tes.
20. **Disk `local` berbagi root dengan `private` dan `serve => true`.** Berkas hanya bisa dibuka lewat URL bertanda tangan (`temporaryUrl`), yang tidak pernah dibuat kode ini. Default: konfigurasi dibiarkan (di luar lingkup), dijaga AC-29; Action dan controller selalu memakai disk `private` secara eksplisit, dan unggahan sementara Livewire tetap di `livewire-tmp` disk bawaan.
21. **Usulan entri baru** (tidak dikerjakan di F010): "psb-input-offline" (panitia memasukkan pendaftar langsung di panel), "psb-koreksi-data" (panitia mengoreksi data pendaftar dengan audit), opsional "psb-retensi-data" (penghapusan/anonimisasi data pendaftar yang tidak diterima setelah periode tertentu, UU PDP).

## Riwayat revisi

- DRAFT 1 (product-manager): rencana awal. Jumlah AC: 40.
- DRAFT 3 (product-manager, review #1 + keputusan orkestrator r3): #1 retry nomor pendaftaran dan nomor tagihan PSB kini loop di luar transaksi, satu `DB::transaction` baru per percobaan, kunci (tahun ajaran lalu gelombang; pendaftar untuk tagihan) diambil paling awal, portabel Postgres; AC-11a baru (bentrok nomor pendaftaran dan tagihan via Action palsu). #2 FK `applicant_id` memakai transaksi bawaan, dipecah tiga migration; alasan DRAFT 1 dikoreksi; AC-4 diganti test perilaku SQLite (down lalu up dengan data terisi). #3 limiter daftar menghitung setiap percobaan kirim (10/jam/IP); `trustProxies` lewat env `TRUSTED_PROXIES` (default kosong); risiko pesan duplikat dicatat. #4 `Hash::check` selalu dijalankan (hash dummy) + limiter per nomor (AC-25). #5 `config/livewire.php` dengan aturan unggah sementara ketat (AC-28a), pendaftar lewat `#[Computed]`, properti sensitif `#[Locked]`. #6 unduh publik 404 seragam, pemuatan eksplisit tanpa global scope, test sesi admin bercakupan (AC-28). #7 test publik `withoutVite`, paket rilis F019 wajib `public/build`, `@source app/Livewire`. #8 assert enum status di DB (AC-2). #9 dicatat di sinkron ERD (langkah 15). #10 gerbang langkah 1–9. Jumlah AC: 42 (tambah AC-11a dan AC-28a).
- DRAFT 2 (product-manager, keputusan orkestrator): pertanyaan 9 (persetujuan UU PDP) diputuskan menyimpan `applicants.consented_at` (timestamp, diisi server oleh `SubmitApplication`, tidak fillable). Diubah: Model data `applicants` + cast, `SubmitApplication` langkah 3, infolist `ViewApplicant`, kunci `pondok.applicant.consented_at`, langkah 14 (sinkron ERD), Risiko #9; AC-1, AC-10, AC-12, AC-22 diperluas. Default lain diterima orkestrator; usulan entri baru tidak ditambahkan. Jumlah AC tetap 40.

## Review #1 — 2026-10-15

Verdict: CHANGES_REQUESTED

Dasar: PRD 3.4, ERD PSB, CLAUDE.md, kode `Invoice`/`Payment`/`UnitScope`/`GenerateInvoiceNumber`/`ListTopArrears`, `config/filesystems.php`, `vite.config.js`, `resources/css/app.css`. Perombakan FK diuji di salinan SQLite sementara (di luar repo, DB kosong dan DB berisi 1 santri + 1 tagihan + 1 item): migration berhasil dan `PRAGMA foreign_key_check` kosong, baik `withinTransaction` true maupun false; rollback juga berhasil. Dependensi: F009 belum `done` (implementasi berjalan), sehingga F010 baru boleh mulai RED setelah F009 `done`.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | `SubmitApplication` langkah 3-4 (penomoran + retry unique) | Retry dijelaskan "muat ulang nomor dan ulangi" tanpa menyebut batas transaksi. Gelombang berbeda dalam tahun yang sama (kunci hanya per baris gelombang, nomor per `YYYY`) bisa berebut nomor. Di MySQL (REPEATABLE READ) snapshot sudah terbentuk oleh cek ganda di langkah 2, sehingga `max(registration_no)` pada retry di transaksi yang sama tidak melihat baris pesaing dan gagal 3 kali (RuntimeException = 500 untuk wali). Di Postgres, unique violation membatalkan seluruh transaksi sehingga retry di dalamnya error. Pola yang sama berlaku untuk nomor tagihan di `GenerateRegistrationInvoices`. | Tulis eksplisit: setiap percobaan = satu `DB::transaction` baru (loop di luar transaksi), kunci gelombang diambil di awal setiap percobaan, dan tidak ada query non-locking sebelum kunci. Opsi lebih sederhana: kunci semua gelombang tahun itu, atau kunci baris `academic_years`. Tambahkan test yang mensimulasikan bentrok nomor (sisipkan nomor yang sama sebelum insert) dan membuktikan percobaan berikutnya berhasil. Sebut portabilitas Postgres di rencana. |
| 2 | minor | Model data "FK `applicant_id`" dan AC-4 | Premis "drop table students gagal di dalam transaksi" tidak terbukti: migration lolos dengan data di dalam transaksi (SQLite menoleransi pelanggaran sementara yang pulih sebelum commit). `withinTransaction = false` tetap aman di SQLite, tetapi pada Postgres menghilangkan atomisitas DDL, dan bila gagal di tabel ke-2 tersisa keadaan setengah jadi. AC-4 mengunci detail implementasi, bukan perilaku. Pembuktian berisi data hanya manual. | Koreksi alasan di rencana. Pilih satu: (a) tetap `false` tetapi pecah menjadi tiga migration (satu tabel per file) agar kegagalan tidak setengah jadi; atau (b) biarkan transaksi bawaan. Ganti AC-4 dengan test perilaku: dalam satu test, `migrate:rollback --step=1` (path migration itu) lalu `migrate` dengan baris santri, tagihan, item, dan pembayaran terisi, assert jumlah baris sama dan `foreign_key_check` kosong (hanya `if driver === sqlite`). Pembuktian MySQL/Postgres tetap tidak boleh diklaim. |
| 3 | minor | Rate limit `psb-register` dan IP | (a) Hanya pendaftaran sukses yang dihitung: bot yang gagal validasi/honeypot tidak dibatasi, dan pendaftaran palsu dari banyak IP dapat menghabiskan kuota gelombang (dihitung tanpa captcha, nomor tidak dipakai ulang). (b) Tidak ada konfigurasi `trustProxies` di `bootstrap/` atau `app/`: di belakang Cloudflare/reverse proxy cPanel, `request()->ip()` bisa IP proxy sehingga seluruh sekolah berbagi batas 5 pendaftaran/jam, atau sebaliknya batas tidak berguna. (c) Pesan `duplicate_application` membuka fakta bahwa kombinasi nama+tanggal lahir+HP sudah terdaftar. | (a) Hitung juga percobaan ditolak (`spam_rejected`, honeypot) pada limiter terpisah yang lebih longgar. (b) Catat di Risiko #8 dan panduan instalasi bahwa `trustProxies` harus diatur bila di balik proxy; jangan mengubah dependensi. (c) Terima sebagai risiko rendah, sebut di Risiko. |
| 4 | minor | `CheckApplicantCredentials` dan limiter status | Nomor tidak ditemukan kembali tanpa `Hash::check`, sedangkan nomor valid memanggil bcrypt: selisih waktu respons memungkinkan enumerasi nomor berurut (`PSB-2027-0001`...). Limiter hanya per IP, sehingga serangan terdistribusi tidak dibatasi per nomor. Token 60 bit menjaga brute force tetap tidak layak, namun nomor valid bocor. | Lakukan `Hash::check` terhadap hash dummy bila nomor tidak ada. Tambahkan limiter kedua per nomor (mis. 10 gagal/jam) selain per IP. |
| 5 | minor | Unggah via Livewire | Endpoint `livewire/upload-file` publik dan menerima berkas apa pun sampai batas bawaan (12 MB; `config/livewire.php` tidak ada) dari siapa saja, tidak terikat sesi `/psb/status`. Aturan `file\|max:2048` hanya pada komponen. | Publikasikan config Livewire dan set `temporary_file_upload.rules` ke `file\|max:2048\|mimes:pdf,jpg,jpeg,png`; tambahkan ke langkah implementasi dan satu test kecil. Pastikan token dan model pendaftar di komponen memakai `#[Locked]`/computed, bukan properti publik `Applicant` (rehydrate akan memakai global scope). |
| 6 | minor | Route unduh publik | `psb.document`: id tidak ada → 404, id milik orang lain → 403 membuka keberadaan id berkas. Binding implisit memakai global scope user login (admin bercakupan yang membuka browser sama akan 404). | Kembalikan 404 untuk semua kasus tidak berhak. Tulis eksplisit bahwa binding memakai `Route::bind`/`withoutGlobalScopes` (rencana hanya menyatakan "dimuat tanpa scope"). Tambahkan test: admin bercakupan SD yang login tetap bisa memakai halaman status/unduh publik pendaftar SLTP lewat kredensial (AC-11 baru menguji `SubmitApplication`). |
| 7 | minor | Aset publik (`vite.config.js`, `.gitignore`) | `public/build` tidak ada dan di-gitignore; layout publik memakai `@vite`, jadi `/psb` dan `/psb/status` error 500 (ViteManifestNotFoundException) di dev dan di rilis tanpa build. Belum ada proses rilis (tanpa `.github`, tanpa dokumen rilis). Tailwind token sudah ada di `resources/css/app.css` sehingga desain tidak butuh perubahan konfigurasi. | Di langkah 15 jadikan jelas: cek visual membutuhkan satu kali `npm run build` (minta izin user), dan catat di laporan bahwa paket rilis wajib memuat `public/build`. Opsional: entri baru "release-package" untuk proses rilis. Pastikan `@source` Tailwind mencakup `app/Livewire` bila ada kelas di PHP. |
| 8 | minor | AC-1 / AC-2 vs `acceptance` F010 | Acceptance "Status: draft, diajukan, ..." belum diassert di level DB: AC-1 hanya `hasColumns`; AC-32 hanya badge. | Tambahkan ke AC-2: insert enam status berhasil, nilai di luar enum gagal (SQLite check constraint, MySQL strict). |
| 9 | minor | Risiko #14 vs ERD "Biaya PSB" | ERD: "Panitia bisa menandai lunas untuk pembayaran tunai"; rencana menyerahkannya ke bendahara. Keputusan sudah diterima orkestrator (default #14), tetapi menyimpang dari ERD. | Cukup catat di sinkronisasi ERD (langkah 14) bahwa pencatatan lunas lewat bendahara/`RecordPayment`. |
| 10 | minor | Ukuran fitur | 40 AC, 15 langkah, dua halaman publik, satu resource, perombakan 3 tabel. Layak tetap satu fitur karena langkah 1-9 dapat dites tanpa UI dan setiap langkah bisa jadi micro-commit, tetapi risiko terbesar (halaman publik + aset) ada di akhir. | Tetap satu fitur; tetapkan gerbang: langkah 1-9 hijau penuh sebelum langkah 10. Bila konteks agent habis, titik pecah alami: setelah langkah 9 (F010a) dan langkah 10-11 (F010b); panel admin (12-13) bisa ikut salah satunya. |

Catatan positif (tidak perlu tindakan): perluasan `Invoice`/`Payment::applyUnitScope` konsisten dengan F008 (`FinanceReportFilter` dan `ListTopArrears` sudah menyaring `student_id` NULL; perubahan test lama F008 mempertahankan maksud dengan pendaftar SLTA); `lockForUpdate` di SQLite no-op tetapi aman karena penulisan SQLite diserialkan; semua AC memetakan acceptance F010 dan PRD 3.4 butir 2-4; `private` disk memakai `serve=false` dan AC-29 menjaga disk `local`.

## Review #2 — 2026-10-15

Verdict: APPROVED

Semua temuan Review #1 sudah ditangani: #1 retry di luar transaksi dengan kunci tahun ajaran lalu gelombang dan AC-11a (juga untuk nomor tagihan, kunci pendaftar); #2 tiga migration bertransaksi bawaan dan AC-4 perilaku SQLite dengan data (sesuai hasil uji saya pada Review #1 bahwa transaksi bawaan lolos); #3 limiter setiap percobaan, `TRUSTED_PROXIES` default kosong, AC-23; #4 `Hash::check` dummy dan limiter per nomor, AC-25; #5 `config/livewire.php`, AC-28a; #6 404 seragam tanpa implicit binding, AC-28; #7 `withoutVite`, `public/build` diwajibkan F019; #8 enum DB; #9 catatan ERD; #10 gerbang langkah 1-9. Tidak ada blocker atau major baru.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Limiter per nomor (AC-25) | Nomor berurut mudah ditebak, sehingga pihak luar dapat mengunci cek status satu pendaftar dengan 10 percobaan gagal per jam (DoS ringan). Dampak terbatas karena panitia bisa membantu dan sesi yang sudah masuk tidak terpengaruh. | Terima sebagai risiko dan catat di Risiko #8. |
| 2 | minor | Risiko #1 | Teks masih menyebut "40 AC"; jumlah sebenarnya 42. | Perbarui angka. |
| 3 | minor | AC-25 (spy `Hash`) | Spy pada facade `Hash` dapat mengganggu `Hash::make` hash dummy statis dan `Hash::make` token di test. | Hitung panggilan lewat spy parsial atau buat hash dummy sebelum spy dipasang; red-tester menyesuaikan tanpa mengubah maksud. |
| 4 | minor | Dependensi | F009 harus `done` sebelum RED F010 dimulai. | Orkestrator mengurutkan. |

