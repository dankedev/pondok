# F012 psb-daftar-ulang — Daftar ulang & konversi menjadi santri

Status rencana: APPROVED

Owner: diklaim orkestrator di `feature_list.json` (product-manager tidak mengubah file itu pada siklus ini). Dependensi: F011 (rencana APPROVED, belum diimplementasikan), F007 (`done`), F013 (rencana APPROVED, belum diimplementasikan). F011 bergantung pada F010 dan F018; F010 dan F013 bergantung pada F009 (sedang berjalan). **RED F012 baru boleh dimulai setelah F010, F011, dan F013 `done`**; orkestrator memverifikasi ulang nama kontrak di bawah terhadap kode final sebelum RED.

Kontrak yang dipakai:
- F002: `CreateStudent` (validasi jenjang = jenjang angkatan), `AttachGuardian` (wali pertama otomatis kontak tagihan), `SetBillingContact`, `Student` (`applicant_id` nullable, `nis` unique, `nisn` unique nullable, `entered_on`, soft delete, global scope `unit_user`), `Guardian` (tanpa global scope), `Cohort` (unique `unit_id` + `entry_year`).
- F005–F007: `FeeOverride` (activitylog `keuangan`), `ResolveFeeOverride::forStudent`, `SaveFeeOverride`, `Invoice` (unique `student_id`+`period`+`billing_group`+`active_marker` dan `applicant_id`+`billing_group`+`active_marker`), `GenerateMonthlyInvoices` (hanya menagih santri `aktif` dengan `entered_on` NULL atau ≤ akhir bulan periode), `RecordPayment`, `CancelPayment`.
- F010: `Applicant` (status, soft delete, global scope, `invoices()`, `feeOverrides()`, `documents()`, `student()`, `guardian_name`, `guardian_phone` ternormalisasi `08…`, `nisn`, `admissionWave.academicYear`), `ApplicantDocument` (`status`, `file_path` disk `private`, `original_name`, `mime_type`, `size_kb`, `verified_by`, `verified_at`, `requirement.documentType`), FK `students.applicant_id`, `ApplicantResource` + `ViewApplicant` + `ListApplicants`, `ApplicationStatus` (Livewire publik), `ApplicantPolicy` (pola method tambahan), `DeleteApplicant` (`applicant_converted`), helper test `pendaftaranFixture()`, `pendaftaranApplicant()`, `pendaftaranPayRegistration()`.
- F011: status `diterima`, `Applicant::acceptanceInvoices()`, `ApplyTrackScholarship`, `GenerateAcceptanceInvoices`, aksi "Buat tagihan penerimaan", `RejectApplicant` (`applicant_converted`), helper test `penerimaanFixture()`, `penerimaanApplicant()`, `penerimaanAccept()`.
- F013: `documents` (`documentable` morph alias `student`/`guardian`, `uploaded_by` wajib, `source_applicant_document_id` tanpa FK, index), `Document` (konstanta `DISK`, `DIRECTORY`, status), `Document::OWNER_TO_MORPH`, `CheckDocumentCompleteness`.

## Ringkasan & tujuan

Panitia PSB menjalankan **daftar ulang** untuk pendaftar berstatus `diterima` yang sudah membayar tagihan penerimaan minimal `psb.min_paid_percent` persen. Satu Action atomik mengubah pendaftar menjadi santri:

1. membuat baris `students` (`applicant_id` terisi, NIS otomatis dari format yang bisa diatur atau diisi manual, angkatan sesuai tahun masuk gelombang yang dibuat bila belum ada, `entered_on` = awal bulan pertama santri ditagih);
2. menyalin data wali ke `guardians` (memakai wali yang sudah ada bila nama dan nomor HP cocok, misalnya kakak sudah mondok) dan menghubungkannya lewat `student_guardian` sebagai kontak tagihan;
3. mengisi `student_id` pada semua `invoices` dan `fee_overrides` milik pendaftar (`applicant_id` tetap), sehingga sisa uang pangkal dan beasiswa jalur (termasuk potongan SPP) ikut menjadi milik santri;
4. menyalin `applicant_documents` yang `valid` menjadi `documents` milik santri (atau wali, sesuai `document_types.owner`), file disalin di disk `private`, `source_applicant_document_id` terisi.

Tagihan bulanan dimulai bulan berikutnya: `entered_on` tidak pernah jatuh di bulan berjalan, dan generator F006 sudah menyaring `entered_on`.

Acuan: PRD 3.4 butir 4–5, PRD 5 butir 2 dan 5, PRD 7 (format NIS masih terbuka); ERD "PSB" (catatan pembuatan `students`), "Biaya PSB" (tahap Daftar ulang, "Pindah ke santri", `psb.min_paid_percent`), "Dokumen" (berkas PSB tidak diunggah ulang, `source_applicant_document_id`), tabel constraint (`students`, `cohorts`, `documents`, `admission_requirements` "F012 memetakan berkas valid ... satu jenis ke satu syarat"); CLAUDE.md "Aturan bisnis utama → PSB", "Testing" (konversi pendaftar menjadi santri); design guide §3, §4, §5, §7, §8, §9.

## Ruang lingkup

- Termasuk:
  - Migration FK `documents.source_applicant_document_id` → `applicant_documents` (`restrictOnDelete`), aman untuk perombakan tabel SQLite, dengan test pelestarian index dan FK (catatan F013).
  - Migration unique `students.applicant_id` (satu santri per pendaftar, ERD `applicants |o--o| students`).
  - Action `app/Actions/Psb/`: `ReRegisterApplicant` (orkestrator), `CheckReRegistrationPayment`, `ResolveApplicantGuardian`, `CopyApplicantDocuments`.
  - Action `app/Actions/Akademik/`: `GenerateStudentNumber` (NIS dari setting `students.nis_format`), `FindOrCreateCohort`.
  - Setting baru `psb.min_paid_percent` (bawaan 50) dan `students.nis_format` (bawaan `{YYYY}{NNNN}`), di-seed `SettingSeeder` tanpa menimpa.
  - Helper model `Applicant::isConverted()` dan `Applicant::convertedStudent()` (tanpa scope, termasuk santri terhapus).
  - Penjaga pada Action yang sudah ada: `GenerateAcceptanceInvoices`/`ApplyTrackScholarship` (F011) dan `SaveFeeOverride` (F005/F010, dengan kunci baris pendaftar) menolak pendaftar yang sudah menjadi santri.
  - Panel: aksi "Catat daftar ulang" dan seksi infolist "Daftar ulang" di `ViewApplicant`, filter "Daftar ulang" di `ListApplicants`, aksi F011 "Buat tagihan penerimaan" tersembunyi setelah konversi.
  - Halaman cek status publik (`ApplicationStatus`): kalimat status setelah daftar ulang.
  - Permission `re-register:applicant` untuk `admin-psb` (dan super-admin).
  - Activitylog: `psb` (konversi), `keuangan` (pemindahan tagihan dan keringanan), `dokumen` (setiap berkas yang disalin).
  - Sinkronisasi `docs/erd.md` dan design guide §3/§4.
- Tidak termasuk (eksplisit):
  - Penempatan rombel (`enrollments`) dan kamar (`room_assignments`). Dilakukan sesudahnya lewat `EnrollStudent`/`AssignRoom` yang sudah ada; notifikasi sukses mengingatkan. Akibatnya santri baru nonmukim sampai ditempatkan.
  - Halaman pengaturan untuk `psb.min_paid_percent` dan `students.nis_format` (belum ada halaman pengaturan umum; usulan entri baru). Nilai diubah lewat tabel `settings`.
  - Menghubungkan pendaftar ke santri yang **sudah ada** (santri internal SD yang mendaftar ke SLTP). Konversi selalu membuat santri baru; NISN bentrok ditolak (`nisn_taken`). Usulan entri baru.
  - Membatalkan daftar ulang (mengembalikan santri menjadi pendaftar).
  - Daftar ulang massal, daftar ulang mandiri oleh wali di halaman publik, dan unggah berkas tambahan saat daftar ulang.
  - Relation manager tagihan di halaman santri ("tunggakan terlihat di profil santri" cukup lewat `InvoiceResource` yang menampilkan NIS setelah konversi; usulan entri baru bila perlu tab khusus).
  - Perubahan status pendaftar: tetap `diterima`; "sudah daftar ulang" diturunkan dari adanya santri (tidak ada nilai enum baru).
  - Pemetaan berkas ke `documents` untuk owner `staff`/`prestasi` (dilewati dengan alasan).

## Model data

Tidak ada tabel atau kolom baru. Satu migration FK:

### FK `documents.source_applicant_document_id` (utang F013)

Migration `..._add_source_applicant_document_foreign_key_to_documents_table` (timestamp setelah migration F010 dan F013 terakhir), transaksi bawaan Laravel (pola F010):
- `up()`: `Schema::table('documents', fn ($t) => $t->foreign('source_applicant_document_id')->references('id')->on('applicant_documents')->restrictOnDelete())`.
- `down()`: `dropForeign(['source_applicant_document_id'])`.
- Index `source_applicant_document_id` dari F013 dipakai ulang (tidak diubah menjadi unique; idempotensi dijaga Action, lihat Risiko #9).
- SQLite membangun ulang `documents` (self-FK `replaced_by_id` unique, FK `document_type_id`, `verified_by`, `uploaded_by`, index `documents_owner_type_index`, `expires_on`, `status`, `source_applicant_document_id`). Test AC-2 memastikan semuanya tetap ada, dan AC-3 menjalankan rollback lalu migrate dengan data terisi (rantai `replaced_by_id`) tanpa kehilangan baris dan `PRAGMA foreign_key_check` kosong. MySQL/Postgres tidak diklaim terverifikasi bila tidak dijalankan.
- Relasi: `Document::sourceApplicantDocument(): BelongsTo`, `ApplicantDocument::copiedDocuments(): HasMany` (tanpa unit scope).

### Unique `students.applicant_id` (review #1 #6)

Migration `..._add_applicant_unique_index_to_students_table` (setelah migration FK F010 dan migration FK `documents` di atas), transaksi bawaan:
- `up()`: `Schema::table('students', fn ($t) => $t->unique('applicant_id', 'students_applicant_id_unique'))`. NULL boleh banyak di SQLite/MySQL/Postgres, jadi santri tanpa asal PSB tidak terpengaruh. Index biasa `students_applicant_id_index` dan FK F010 dibiarkan.
- `down()`: `dropUnique('students_applicant_id_unique')`.
- Di SQLite, `CREATE UNIQUE INDEX` tidak membangun ulang tabel `students` (yang dirujuk banyak tabel anak). Test AC-40 tetap memastikan FK dan index `students` utuh.
- Data lama dengan `applicant_id` ganda tidak mungkin ada (belum ada jalur yang mengisinya sebelum F012); bila migration gagal karena itu, laporkan, jangan dibersihkan otomatis.
- Melengkapi kunci baris (no-op di SQLite) dan juga melindungi jalur lain seperti `CreateStudent` dengan `applicant_id` manual.

### Kolom yang dipakai dan arti barunya

| Tabel.kolom | Nilai saat daftar ulang |
|---|---|
| `students.unit_id` | `applicants.unit_id` |
| `students.cohort_id` | angkatan (`unit_id`, `entry_year` = tahun `starts_on` tahun ajaran gelombang; 2027/2028 → 2027), dibuat bila belum ada dengan `name` = `__('pondok.cohort.default_name', ['year' => 2027])` "Angkatan 2027" |
| `students.applicant_id` | id pendaftar |
| `students.nis` | input manual, atau `GenerateStudentNumber` |
| `students.nisn`, `name`, `gender`, `birth_place`, `birth_date` | salinan dari pendaftar (snapshot; koreksi selanjutnya di data santri) |
| `students.address` | input opsional form daftar ulang (pendaftar tidak punya alamat) |
| `students.status` | `aktif` |
| `students.entered_on` | **tanggal mulai santri ditagih bulanan**: input, atau bawaan `max(awal bulan depan, starts_on tahun ajaran gelombang)` |
| `guardians.name`, `phone` | `applicants.guardian_name`, `guardian_phone` (wali baru); wali cocok dipakai ulang tanpa diubah |
| `student_guardian.relation` | input `ayah` / `ibu` / `wali` (bawaan `wali`) |
| `student_guardian.is_billing_contact` | true (wali pertama santri, aturan `AttachGuardian`) |
| `invoices.student_id`, `fee_overrides.student_id` | id santri baru; `applicant_id` tetap |
| `documents.*` | lihat `CopyApplicantDocuments` |

### Settings

| Key | Bawaan | Aturan baca |
|---|---|---|
| `psb.min_paid_percent` | `50` | bilangan bulat 0–100 (int atau string digit). Selain itu → bawaan 50 + `Log::warning` (pola `keuangan.due_day` F006). |
| `students.nis_format` | `{YYYY}{NNNN}` | lihat `GenerateStudentNumber`. Tidak valid → bawaan + `Log::warning`. |

`SettingSeeder` menambah keduanya dengan `firstOrCreate` (nilai yang sudah diubah tidak ditimpa).

### Helper `Applicant` (tambahan)

- `convertedStudent(): ?Student` = `Student::withoutGlobalScopes()->withTrashed()->where('applicant_id', $this->id)->first()`.
- `isConverted(): bool` = `convertedStudent() !== null`. Dipakai untuk visibilitas UI dan penjaga Action. Relasi `student()` F010 tidak diubah.

## Action class

Semua Action melempar `ValidationException::withMessages([$field => __("pondok.psb.errors.{$reason}")])` (pola F009–F011), kecuali `GenerateStudentNumber` dan `FindOrCreateCohort` yang memakai `pondok.akademik.errors.*`. "Hari ini" = `today()` (zona aplikasi `Asia/Jakarta`). Action tidak memeriksa permission (dicek policy di panel).

### `App\Actions\Psb\CheckReRegistrationPayment`

`handle(Applicant $applicant): array{applies: bool, required_percent: int, total: int, paid: int, paid_percent: int, meets: bool, reason: ?string}` — murni baca, tanpa cakupan jenjang.

- `required_percent` = setting `psb.min_paid_percent` tervalidasi (bawaan `DEFAULT_MIN_PERCENT = 50`).
- Modul keuangan mati → `applies = false`, `meets = true` (tidak ada syarat pembayaran).
- Basis = tagihan **penerimaan** aktif pendaftar (`acceptanceInvoices()` F011: bukan `batal`, punya item `saat_diterima`), dibaca dengan `Invoice::withoutUnitScope()`. Tagihan formulir (`saat_daftar`) dan tagihan `batal` tidak dihitung.
- `total` = jumlah `invoices.total`, `paid` = jumlah `paid_total` (bigint rupiah). `paid_percent` = `total = 0 ? 100 : intdiv(paid * 100, total)` (bulat ke bawah, untuk tampilan).
- `meets` = `paid * 100 >= total * required_percent` (aritmetika bilangan bulat, tanpa float).
- Tanpa tagihan penerimaan aktif: bila ada jenis biaya aktif `saat_diterima` yang bertarif untuk pendaftar ini (`ResolveFeeRate::handle($type, $wave->academicYear, $unit_id, null, null, $wave->id)` tidak NULL) → `meets = false`, `reason = acceptance_invoices_missing`; selain itu `meets = true` (tidak ada biaya penerimaan).
- Kurang dari syarat → `reason = min_payment_not_met`.

### `App\Actions\Akademik\GenerateStudentNumber`

`handle(Unit $unit, int $entryYear): string`; `static isValidFormat(string $format): bool`; konstanta `DEFAULT_FORMAT = '{YYYY}{NNNN}'`, `SETTING_KEY = 'students.nis_format'`.

- Token: `{YYYY}` tahun masuk 4 digit, `{YY}` 2 digit terakhir, `{UNIT}` `units.code`, dan tepat satu token urut `{N…}` berisi 3–6 huruf `N` (lebar nol di depan) yang **harus berada di akhir** format. Karakter literal lain yang boleh: huruf, angka, `.`, `-`, `/`. Panjang hasil ≤ 30. Selain itu format tidak valid → bawaan + `Log::warning` (format dicatat, bukan data santri).
- Prefiks = format sebelum token urut setelah token lain diganti. Urutan berikutnya = nomor terbesar di antara `Student::withoutGlobalScopes()->withTrashed()` yang `nis` cocok regex `^{prefiks}\d{lebar}$` (kandidat diambil dengan `LIKE prefiks%` dan hanya kolom `nis` lewat `pluck('nis')`, lalu dicocokkan dan dicari maksimumnya di PHP; portabel, tanpa memuat model) + 1, mulai 1. Santri terhapus ikut dihitung (nomor tidak dipakai ulang).
- Melebihi kapasitas lebar (mis. 999 untuk `NNN`) → `nis`/`nis_sequence_exhausted`.
- Contoh: bawaan + 2027 → `20270001`; `{UNIT}-{YY}-{NNN}` + SLTP 2027 → `SLTP-27-001`.

### `App\Actions\Akademik\FindOrCreateCohort`

`handle(Unit $unit, int $entryYear): Cohort` — `Cohort` dengan (`unit_id`, `entry_year`) atau dibuat (`name` "Angkatan 2027"). Bentrok unique saat dibuat bersamaan → `UniqueConstraintViolationException` diteruskan ke pemanggil (ditangani loop percobaan `ReRegisterApplicant`).

### `App\Actions\Psb\ResolveApplicantGuardian`

`handle(Applicant $applicant): array{guardian: Guardian, reused: bool}` — dipanggil di dalam transaksi `ReRegisterApplicant`.

- Varian nomor dari `guardian_phone` (`08xxx`): `08xxx`, `628xxx`, `+628xxx`. Kandidat = `Guardian` dengan `phone` ∈ varian **dan** `LOWER(TRIM(name))` sama dengan nama wali pendaftar (dibandingkan di PHP setelah `mb_strtolower(trim())`, spasi ganda dirapikan). Ada → kandidat dengan id terkecil, `reused = true`, data wali lama **tidak diubah**.
- Tidak ada (termasuk nomor sama tetapi nama berbeda, atau nomor tersimpan dengan format lain seperti `0812-345…`) → `Guardian` baru (`name`, `phone`), `reused = false`. Lebih baik wali ganda daripada salah menggabungkan keluarga (Risiko #5).

### `App\Actions\Psb\CopyApplicantDocuments`

`handle(Applicant $applicant, Student $student, Guardian $guardian, User $by, array &$copiedPaths): array{copied: list<int>, skipped: list<array{applicant_document_id: int, reason: string}>}` — dipanggil di dalam transaksi `ReRegisterApplicant`.

Untuk setiap `ApplicantDocument::withoutGlobalScopes()` milik pendaftar dengan `status = valid`, urut `id`, eager-load `requirement.documentType`:
1. Sudah punya `documents` dengan `source_applicant_document_id` = id ini (tanpa unit scope) → `skipped` `already_copied`.
2. Pemilik dari `Document::OWNER_TO_MORPH[documentType.owner]`: `student` → santri baru; `guardian` → wali hasil `ResolveApplicantGuardian`; lainnya → `skipped` `owner_not_supported`.
3. Pemilik `guardian` yang **sudah punya** dokumen current (`replaced_by_id` NULL, status apa pun, tanpa unit scope) dengan `document_type_id` yang sama → `skipped` `owner_has_current`; dokumen lama tidak disentuh (keputusan orkestrator, review #1 #3: menjaga invarian F013 satu versi current per jenis dan pemilik; panitia/admin sekolah bisa mengunggah versi baru lewat `ReplaceDocument` bila perlu). Hanya mungkin untuk wali yang dipakai ulang. Pemilik santri selalu baru sehingga tidak perlu dicek; dua syarat dengan jenis sama untuk satu pendaftar tidak mungkin (keterbatasan F009), tetapi bila terjadi, yang kedua juga `owner_has_current`.
4. File sumber tidak ada di disk `private` → `skipped` `file_missing` + `Log::warning` (registration_no, applicant_document_id). Konversi tetap berjalan.
5. Salin file (`Storage::disk('private')->copy`) ke `documents/{alias}/{owner_id}/{Str::random(40)}.{ext}` (ekstensi dari `mime_type`: `pdf`, `jpg`, `png`). Hasil `copy()` `false` (disk tanpa opsi `throw`) atau exception → `RuntimeException` (`pondok.psb.errors.file_copy_failed`, pesan log memuat applicant_document_id saja), yang membuat seluruh percobaan di-rollback dan dikompensasi; tidak ada baris `documents` yang menunjuk file tak ada. Tambahkan path ke `$copiedPaths` **segera setelah** `copy()` mengembalikan true. File sumber dan baris `applicant_documents` tidak diubah.
6. Buat `Document`: `document_type_id` = jenis syarat (jenis nonaktif tetap disalin), `documentable` = pemilik, `number`/`issued_on`/`expires_on` NULL, `file_path` baru, `original_name`, `mime_type`, `size_kb` disalin, `status = valid`, `verified_by`/`verified_at` disalin dari berkas PSB, `uploaded_by = $by->id`, `replaced_by_id` NULL, `source_applicant_document_id` = id berkas PSB.
7. Satu activity per dokumen: log `dokumen`, subject dokumen, causer `$by`, event `copied_from_applicant`, properti `document_type` (code), `documentable_type` (alias), `documentable_id`, `source_applicant_document_id`, `status`. **Tanpa** `number`, `file_path`, `original_name`.

Tidak memeriksa saklar modul `dokumen` (data tetap disalin; saklar hanya menyembunyikan UI).

### `App\Actions\Psb\ReRegisterApplicant`

`handle(Applicant $applicant, User $by, array $data = []): array{student: Student, guardian: Guardian, guardian_reused: bool, invoices: int, fee_overrides: int, documents_copied: int, documents_skipped: list<array{applicant_document_id: int, reason: string}>}`

`$data`: `nis?` (string kosong/NULL = otomatis), `entered_on?` (`Y-m-d`; kosong = bawaan), `relation?` (`ayah`/`ibu`/`wali`, bawaan `wali`), `address?` (teks, kosong → NULL).

**Normalisasi sebelum transaksi** (gagal → tidak ada perubahan):
- `relation` ∉ `Guardian::RELATIONS` → `relation`/`relation_invalid`.
- `address`: trim; > 1000 karakter → `address`/`address_too_long`.
- `nis` manual: trim; harus `^[A-Za-z0-9.\-\/]{1,30}$` → `nis`/`nis_invalid`.
- `entered_on`: format ketat `Y-m-d` → `entered_on`/`date_invalid`; harus ≥ awal bulan depan → `entered_on`/`entered_on_too_early` ("Tanggal masuk paling cepat 1 Nov 2026, karena tagihan bulanan dimulai bulan berikutnya." dengan `:date` format `j M Y`, design guide §5).

**Loop percobaan di luar transaksi** (maks `MAX_ATTEMPTS = 3`, pola F010), setiap percobaan satu `DB::transaction` baru dan `$copiedPaths = []`:
1. Kunci paling awal: `Applicant::withoutGlobalScopes()->withTrashed()->lockForUpdate()->find($id)`. Terhapus → `applicant`/`applicant_not_found`. Status bukan `diterima` → `status`/`applicant_not_accepted`. `isConverted()` → `applicant`/`already_converted`.
2. Kunci tagihan pendaftar (`Invoice::withoutUnitScope()->where('applicant_id', ...)->lockForUpdate()->get()`), lalu `CheckReRegistrationPayment`; `meets = false` → `payment`/`min_payment_not_met` (`:paid` persen, `:required` persen) atau `payment`/`acceptance_invoices_missing`.
3. `nisn` pendaftar terisi dan sudah dipakai `Student::withoutGlobalScopes()->withTrashed()` → `nisn`/`nisn_taken`. NIS manual sudah dipakai (termasuk santri terhapus) → `nis`/`nis_taken`.
4. `entered_on` bawaan = `max(awal bulan depan, starts_on tahun ajaran gelombang)`.
5. Angkatan `FindOrCreateCohort::handle($unit, $entryYear)`; NIS = manual atau `GenerateStudentNumber::handle($unit, $entryYear)`; santri dibuat lewat `CreateStudent::handle([...])`.
6. Wali `ResolveApplicantGuardian`, lalu `AttachGuardian::handle($student, $guardian, $relation)` (kontak tagihan otomatis).
7. Pemindahan keuangan di dalam `activity()->withoutLogs(...)`:
   - id tagihan = tagihan yang dikunci di langkah 2 (status apa pun, termasuk `lunas` dan `batal`); id keringanan = `FeeOverride::where('applicant_id', ...)->whereNull('student_id')->lockForUpdate()->pluck('id')` (termasuk yang sudah berakhir).
   - Masing-masing satu query `whereKey($ids)->whereNull('student_id')->update(['student_id' => ..., 'updated_at' => now()])`. Jumlah baris yang diperbarui harus sama dengan jumlah id; selain itu `RuntimeException` (`pondok.psb.errors.billing_transfer_mismatch`) → rollback + kompensasi (tanda ada proses lain yang mengubah data di sela kunci).
   - Nominal, item, pembayaran, alokasi, `applicant_id`, masa berlaku tidak berubah.
   - Keringanan yang dibuat `SaveFeeOverride` sebelum kunci pendaftar diambil ikut terbaca di sini (dibaca dari DB, bukan dari relasi instance masukan); yang mencoba dibuat sesudahnya menunggu kunci lalu ditolak `applicant_converted` (lihat Perubahan Action yang sudah ada).
8. `CopyApplicantDocuments::handle(..., $copiedPaths)`.
9. Activity manual:
   - log `psb`, subject pendaftar, causer `$by`, event `converted`, deskripsi `pondok.applicant.activity_converted`, properti `student_id`, `nis`, `guardian_id`, `guardian_reused`, `invoices`, `fee_overrides`, `documents_copied` (tanpa nama, NISN, telepon);
   - log `keuangan`, subject santri baru, causer `$by`, event `applicant_billing_transferred`, properti `applicant_id`, `invoice_ids` (terurut), `fee_override_ids` (terurut). Dibuat walau keduanya kosong (jejak audit konversi).

**Kegagalan:** exception apa pun di dalam percobaan → transaksi rollback, lalu setiap path di `$copiedPaths` dihapus dari disk `private` (kompensasi) sebelum exception diteruskan atau percobaan diulang. Setiap penghapusan dibungkus `try/catch (Throwable)`; kegagalan hapus hanya `Log::warning` berisi path (tanpa data santri) dan **tidak** menimpa exception asli. `UniqueConstraintViolationException` ditangkap **di luar** transaksi: bila pendaftar kini sudah punya santri (unique `students.applicant_id`, konversi bersamaan) → `applicant`/`already_converted` (tidak diulang); NIS manual → `nis`/`nis_taken` (tidak diulang); selain itu (NIS otomatis atau angkatan dibuat bersamaan) → percobaan berikutnya; gagal 3 kali → `Log::error` + `RuntimeException` (`pondok.psb.errors.student_number_conflict`), tanpa baris dan file tersisa. `ValidationException` tidak diulang.

**Asumsi pemanggilan:** `ReRegisterApplicant` tidak boleh dipanggil di dalam transaksi pemanggil (loop percobaan dan penangkapan unique violation di luar transaksi hanya benar bila transaksinya milik Action ini; di Postgres transaksi luar akan batal). Dicatat di PHPDoc kelas; aksi panel memanggilnya langsung.

Konsekuensi yang disengaja: proses yang mati tepat setelah file disalin dan sebelum commit meninggalkan file yatim di `documents/` tanpa baris (tidak terbaca siapa pun; Risiko #10).

### Perubahan Action yang sudah ada

- `GenerateAcceptanceInvoices` dan `ApplyTrackScholarship` (F011): setelah kunci baris pendaftar dan cek status, pendaftar yang `isConverted()` → `applicant`/`applicant_converted` (tagihan atau keringanan baru dengan `applicant_id` saja tidak akan pernah pindah ke santri). `AcceptApplicant` tidak terpengaruh (status `diterima` → `diterima` sudah `invalid_transition`).
- `SaveFeeOverride` (F005/F010, review #1 #1): saat **membuat** keringanan dengan `applicant_id`, transaksi yang ada diawali `Applicant::withoutGlobalScopes()->withTrashed()->lockForUpdate()->find($applicantId)` **sebelum** cek tumpang tindih; pendaftar yang pada kondisi terkunci itu `isConverted()` → `applicant_id`/`applicant_converted` (`pondok.keuangan.errors`, "Pendaftar ini sudah menjadi santri. Buat keringanan untuk santri :nis."). Karena `ReRegisterApplicant` memegang kunci baris yang sama sampai commit, keringanan pendaftar selalu dibuat sebelum konversi (lalu ikut dipindahkan) atau ditolak sesudahnya; tidak ada keringanan yang tertinggal ber-`student_id` NULL. Edit keringanan lama tidak berubah. Pemeriksaan keberadaan `applicant_id` versi F010 (cakupan user) tetap dijalankan lebih dulu.
- `RejectApplicant` (F011) dan `DeleteApplicant` (F010) sudah menolak `applicant_converted` lewat `student()`. F012 menggantinya dengan `isConverted()` agar santri hasil konversi yang di-soft-delete tetap mengunci pendaftar.

## UI (Filament / Blade)

Rujukan design guide: §0 checklist, §3 (ikon Heroicon Outlined, kapital di awal kalimat), §4 (badge), §5 (`Rp 5.000.000`, persen bilangan bulat, tanggal `1 Jul 2027`), §7 state, §8 (aksi berdampak memakai modal dengan deskripsi akibat; label kata kerja + objek), §9 (panel tanpa sapaan, pesan error menyebut cara memperbaiki; publik "Anda"), §13 (anti-ui-slop, panggil `/anti-ui-slop` bila tersedia).

### `ViewApplicant` (F010/F011) — aksi header `reRegisterAction`

- Label "Catat daftar ulang", warna `success`, ikon `Heroicon::OutlinedAcademicCap`. Tampil bila policy `reRegister` (permission `re-register:applicant`), status `diterima`, dan belum `isConverted()`. Tidak disembunyikan saat pembayaran kurang: Action menolak dengan pesan yang menjelaskan sisa syarat (state dijelaskan juga di seksi infolist).
- Modal form (`columns(2)`):
  - `entered_on` (DatePicker, wajib, bawaan dari aturan bawaan, `minDate` awal bulan depan, helper "Tagihan bulanan santri dimulai dari bulan ini.").
  - `nis` (TextInput opsional, helper "Kosongkan untuk dibuat otomatis, contoh: :example." — contoh dari `GenerateStudentNumber` pratinjau tanpa menyimpan).
  - `relation` (Select `ayah`/`ibu`/`wali`, bawaan `wali`, label "Hubungan wali (:name)").
  - `address` (Textarea opsional, `columnSpanFull`).
- Deskripsi modal (akibat): "Data santri dibuat dari data pendaftar. Tagihan dan keringanan pendaftar dipindahkan ke santri, dan berkas yang valid disalin ke dokumen santri. Daftar ulang tidak bisa dibatalkan dari panel."
- Error Action tampil di field form (`ShowsActionErrorsOnForm`); error tanpa field form (`payment`, `applicant`, `status`, `nisn`) tampil sebagai notifikasi danger.
- Berhasil: notifikasi success persistent "Daftar ulang selesai. :name menjadi santri dengan NIS :nis." + kalimat kedua "Tempatkan santri ke rombel dan kamar." Bila `documents_skipped` tidak kosong: notifikasi `warning` "Sebagian berkas tidak disalin: :items." (nama syarat + alasan dari `pondok.applicant.copy_skip_reasons.*`). Infolist dimuat ulang.

### `ViewApplicant` — seksi infolist "Daftar ulang"

Tampil bila status `diterima` (setelah seksi "Keputusan" F011):
- Belum daftar ulang: "Pembayaran tagihan penerimaan" = "Rp 4.000.000 dari Rp 8.000.000 (50%)" (atau "Tidak ada tagihan penerimaan" / "Modul keuangan tidak aktif"), "Syarat minimal" = ":required%", badge "Memenuhi syarat" (`success`) atau "Belum memenuhi" (`warning`; bila `acceptance_invoices_missing`, teks bantu "Tagihan penerimaan belum dibuat.").
- Sudah daftar ulang: badge "Sudah daftar ulang" (`success`), "Santri" = "NIS — nama" sebagai tautan ke halaman edit `StudentResource` bila user boleh `update` santri itu (selain itu teks saja), "Tanggal masuk" (`entered_on`), waktu daftar ulang (dari activity `converted`, `j M Y, H.i`).

### Perubahan UI lain

- `ListApplicants`: `TernaryFilter` "Daftar ulang" (`true` = sudah menjadi santri, `false` = diterima dan belum) memakai `whereExists`/`whereNotExists` ke `students.applicant_id` tanpa scope santri (termasuk terhapus).
- Aksi F011 "Buat tagihan penerimaan": tersembunyi bila `isConverted()`.
- `ApplicationStatus` (publik): pendaftar `diterima` yang sudah menjadi santri menampilkan kalimat `pondok.psb_public.status_help.converted` "Daftar ulang selesai. Ananda sudah terdaftar sebagai santri dengan nomor induk :nis." menggantikan `status_help.diterima`. Unggah berkas tetap tertutup (aturan F010). Tagihan tetap tampil (masih milik pendaftar lewat `applicant_id`).

### Teks `lang/id/pondok.php` (kunci baru)

`applicant.{re_register, re_register_heading (:name), re_register_description, entered_on, entered_on_helper, nis, nis_helper (:example), guardian_relation (:name), address, re_registered (:name, :nis), re_registered_next_step, copy_skipped (:items), copy_skip_reasons.{already_copied, owner_not_supported, owner_has_current, file_missing}, section_re_registration, acceptance_payment, acceptance_payment_value (:paid, :total, :percent), no_acceptance_invoices, finance_disabled, min_paid_percent (:percent), re_registration_ready, re_registration_not_ready, acceptance_invoices_not_created, converted, converted_student, converted_at, filter_re_registered, activity_converted}`; `cohort.default_name (:year)`; `psb.errors.{applicant_not_accepted (pakai ulang F011), applicant_not_found (pakai ulang), already_converted, min_payment_not_met (:paid, :required), acceptance_invoices_missing, nisn_taken, nis_taken, nis_invalid, relation_invalid, address_too_long, date_invalid, entered_on_too_early (:date), student_number_conflict, file_copy_failed, billing_transfer_mismatch, applicant_converted (pakai ulang)}`; `akademik.errors.{nis_sequence_exhausted}`; `keuangan.errors.applicant_converted (:nis)`; `psb_public.status_help.converted (:nis)`. Kunci error ditulis bersama Action yang memakainya. Contoh pesan: `min_payment_not_met` = "Pembayaran tagihan penerimaan baru :paid%. Daftar ulang membutuhkan minimal :required%."; `nisn_taken` = "NISN pendaftar sudah dipakai santri lain. Bila santri ini lanjut dari jenjang sebelumnya, gunakan perpindahan jenjang di data santri."; `acceptance_invoices_missing` = "Tagihan penerimaan belum dibuat. Gunakan Buat tagihan penerimaan lebih dulu."

### Design guide

- §4: baris PSB "diterima, daftar ulang | success" diperjelas: "Daftar ulang | sudah daftar ulang | `success`", "Daftar ulang | belum memenuhi syarat | `warning`".
- §3: konsep "Daftar ulang → `AcademicCap`".

## Permission & cakupan

- Permission baru: `EXTRA_RESOURCE_ACTIONS['Applicant']` ditambah `reRegister` → `re-register:applicant`. `ApplicantPolicy::reRegister(User, Applicant)` (pola method tambahan F010/F011). Test Shield "creates every permission that a generated policy checks" tetap lulus.
- Pembagian: `admin-psb` mendapat `re-register:applicant`; `super-admin` semua. `admin-sekolah`, `bendahara`, dan peran lain tidak (bisa diberikan lewat Shield). Santri, wali, tagihan, dan dokumen dibuat sebagai langkah sistem di dalam Action walau `admin-psb` tidak punya permission `Student`/`Guardian`/`Document`/`FeeOverride` (Risiko #6).
- Cakupan jenjang: aksi panel bekerja pada record yang sudah tersaring global scope `Applicant`; pendaftar di luar cakupan → 404 (F010). Action membaca ulang tanpa scope hanya untuk mengunci baris yang sama. Santri baru berjenjang sama dengan pendaftar, sehingga user bercakupan yang menjalankan daftar ulang melihat santri dan tagihannya.
- Saklar modul: `modules.psb.enabled` mati → `ApplicantResource` 403 (F010). `modules.keuangan.enabled` mati → syarat pembayaran tidak berlaku, tagihan dan keringanan yang sudah ada tetap dipindahkan, seksi infolist menampilkan "Modul keuangan tidak aktif". `modules.dokumen.enabled` mati → berkas tetap disalin. `modules.akademik.enabled` tidak dicek oleh Action.

## Kriteria penerimaan (bisa dites)

Fixture bersama (lihat Rencana test): di atas `penerimaanFixture()` (F011, yang memakai `pendaftaranFixture()` F010: tahun ajaran `Y2026` 2026-07-01..2027-06-30 aktif, `Y2027` 2027-07-01..2028-06-30, gelombang `W1` untuk `Y2027`, waktu 2026-10-15 10:00 Asia/Jakarta, jenis biaya `FORMULIR` `saat_daftar` grup `PSB_DAFTAR`); ditambah jenis biaya `UANG_PANGKAL` (`saat_diterima`, grup `PSB_TERIMA`, prioritas 10) tarif SLTP `Y2027` Rp6.000.000, `SERAGAM` (`saat_diterima`, grup `PSB_TERIMA`, prioritas 20) Rp2.000.000, `SPP` (`bulanan`, grup `BULANAN`) tarif SLTP `Y2026` dan `Y2027` Rp500.000; pendaftar P (SLTP, jalur `REGULER`, nama "Ahmad Fauzan", NISN `0123456789`, wali "Budi Santoso" `081234567890`) berstatus `diterima` dengan tagihan formulir lunas dan satu tagihan penerimaan `PSB_TERIMA` total Rp8.000.000; user `admin-psb` A tanpa cakupan; `Storage::fake('private')`.

**Migration dan setting**

- AC-1: Setelah `migrate`, `Schema::getForeignKeys('documents')` memuat FK `source_applicant_document_id` → `applicant_documents.id` dengan aksi hapus `restrict`/`no action`; menghapus baris `applicant_documents` yang dirujuk dokumen gagal (`QueryException`).
- AC-2: Setelah migration F012 di SQLite, `documents` tetap punya FK `document_type_id`, `verified_by`, `uploaded_by`, `replaced_by_id` (ke `documents`), index `documents_owner_type_index` (`documentable_type`, `documentable_id`, `document_type_id`), index `expires_on`, index `status`, index `source_applicant_document_id`, dan unique `replaced_by_id`; kolom `number` tetap `text` (nama dan kolom index dibandingkan dengan keadaan sebelum migration F012 di test yang sama).
- AC-3: Dengan dua dokumen terisi (satu `replaced_by_id` menunjuk yang lain) dan satu dokumen bersumber berkas PSB: `migrate:rollback --step=1` lalu `migrate` di SQLite → berhasil, jumlah dan isi baris `documents` sama, `PRAGMA foreign_key_check` kosong, AC-2 tetap berlaku.
- AC-4: `SettingSeeder` pada `settings` kosong membuat `psb.min_paid_percent` = 50 dan `students.nis_format` = `{YYYY}{NNNN}`; nilai yang sudah diubah (mis. 100 dan `{UNIT}{NNNN}`) tidak ditimpa seed ulang.

**Syarat pembayaran (`CheckReRegistrationPayment`)**

- AC-5: Tagihan penerimaan Rp8.000.000 terbayar Rp3.999.999 → `paid_percent` 49, `meets` false, `reason` `min_payment_not_met`; `ReRegisterApplicant` gagal `min_payment_not_met` pada field `payment` dan pesannya memuat "49%" dan "50%"; tidak ada santri, wali, dokumen, maupun perubahan tagihan. Setelah total terbayar Rp4.000.000 (lewat `RecordPayment`) → `meets` true dan daftar ulang berhasil.
- AC-6: Setting `psb.min_paid_percent` = 100 → terbayar Rp7.999.999 ditolak, lunas diterima. Setting 0 → tanpa pembayaran diterima. Setting `"abc"`, `150`, `-1`, `"50.5"` → dianggap 50 dan `Log::warning` tercatat.
- AC-7: Basis hanya tagihan penerimaan aktif: tagihan formulir lunas tidak menambah persen; tagihan penerimaan `batal` (Rp8.000.000) plus tagihan penerimaan pengganti aktif Rp8.000.000 terbayar Rp4.000.000 → `total` 8.000.000, `meets` true. Tagihan penerimaan total Rp0 (beasiswa nominal 0, status `lunas`) → `paid_percent` 100, `meets` true.
- AC-8: Tanpa tagihan penerimaan aktif sementara `UANG_PANGKAL` bertarif → `acceptance_invoices_missing` (daftar ulang ditolak dengan pesan itu). Tanpa jenis biaya `saat_diterima` bertarif (tarif dihapus dari fixture) → `meets` true. Modul keuangan mati → `applies` false, `meets` true, daftar ulang berhasil walau tagihan penerimaan belum dibayar, dan tagihan yang ada tetap dipindahkan.

**Konversi (`ReRegisterApplicant`) — test wajib CLAUDE.md**

- AC-9: P memenuhi syarat; P juga punya keringanan khusus `SERAGAM` (berakhir kemarin), keringanan `SPP` persen 50 (`valid_until` NULL, dibuat lewat factory dengan `applicant_id` P, selanjutnya disebut "keringanan jalur"), dua berkas `valid` (KK dan AKTA, owner `santri`), satu berkas `menunggu` (RAPOR), dan satu tagihan formulir `batal` lama. `actingAs(A)`, `handle(P, A, ['relation' => 'ayah'])` → tepat satu `students` baru dengan `applicant_id` = P, `unit_id` SLTP, `name`, `gender`, `birth_place`, `birth_date`, `nisn` sama dengan P, `status` `aktif`, `address` NULL; **semua** tagihan P (formulir lunas, formulir `batal`, penerimaan) kini `student_id` = santri dengan `applicant_id` tetap P, dan `total`, `paid_total`, `status`, item (`base_amount`, `discount_amount`, `amount`, `paid_amount`), pembayaran, dan alokasi tidak berubah; **semua** keringanan P (termasuk yang sudah berakhir) kini `student_id` = santri, `applicant_id` tetap, masa berlaku dan nilai tidak berubah; tepat dua `documents` baru (KK, AKTA) milik santri; RAPOR `menunggu` tidak disalin. Hasil: `invoices` 3, `fee_overrides` 2, `documents_copied` 2.
- AC-10: Tagihan, keringanan, dan berkas milik pendaftar lain Q (`diterima`) tidak berubah oleh daftar ulang P.
- AC-11: Setelah AC-9, `ResolveFeeOverride::forStudent(santri, SPP, 2027-07-01)` mengembalikan keringanan jalur; `Student::invoices()` santri berisi tiga tagihan; `InvoiceResource` (tabel, sebagai bendahara) menampilkan NIS santri untuk tagihan penerimaan.

**Data santri, NIS, angkatan, tanggal masuk**

- AC-12: Belum ada angkatan SLTP 2027 → dibuat satu `cohorts` (`unit_id` SLTP, `entry_year` 2027, `name` "Angkatan 2027"); daftar ulang pendaftar kedua di jenjang yang sama memakai angkatan itu (jumlah `cohorts` tetap). Angkatan SLTP 2027 yang sudah ada (nama lain) dipakai ulang tanpa diubah.
- AC-13: Format bawaan: daftar ulang pertama → NIS `20270001`, kedua → `20270002`; bila sudah ada santri terhapus (soft delete) ber-NIS `20270005` dan santri lain `2027000A` (tidak cocok pola) → berikutnya `20270006`.
- AC-14: Setting `students.nis_format` = `{UNIT}-{YY}-{NNN}` → `SLTP-27-001`. Format tidak valid (`{YYYY}`, `{NNNN}{YYYY}`, `{YYYY}{N}`, `{FOO}{NNNN}`, `{YYYY}{NNNN}{NNNN}`, `{YYYY} {NNNN}`) → NIS memakai format bawaan dan `Log::warning` tercatat. Dengan format `{UNIT}{NNN}` dan santri `SLTP999` sudah ada → `nis_sequence_exhausted` pada field `nis`, tidak ada perubahan.
- AC-15: NIS manual `" 2027-SLTP-01 "` → tersimpan `2027-SLTP-01`. NIS manual yang sudah dipakai santri (termasuk santri terhapus) → `nis_taken`; NIS manual `"20 27"` atau 31 karakter → `nis_invalid`; `address` 1001 karakter → `address_too_long` (1000 karakter diterima, tersimpan setelah trim); semuanya tanpa perubahan apa pun.
- AC-16: NISN P sudah dipakai santri lain (termasuk terhapus) → `nisn_taken` pada field `nisn`, tanpa perubahan. Pendaftar tanpa NISN → santri `nisn` NULL dan dua santri tanpa NISN bisa dibuat.
- AC-17: `entered_on` bawaan: pada 2026-10-15 untuk gelombang `Y2027` → `2027-07-01`; dengan `travelTo('2027-08-15')` → `2027-09-01`. Input `2026-11-01` diterima; input `2026-10-31` → `entered_on_too_early` (pesan memuat "1 Nov 2026" atau format tanggal design guide); input `"2026-13-01"` atau `"01-11-2026"` → `date_invalid`. Batas akhir bulan: dengan `travelTo('2026-10-31 23:30')` bawaan tetap `2027-07-01` untuk `Y2027`, input `2026-10-31` ditolak dan `2026-11-01` diterima; untuk pendaftar gelombang tahun ajaran yang sudah mulai (`travelTo('2027-08-31 23:30')`) bawaan `2027-09-01`.
- AC-18: Generator bulanan tidak menagih santri baru di bulan berjalan: setelah daftar ulang (2026-10-15, `entered_on` 2027-07-01), `GenerateMonthlyInvoices` untuk 2026-10, 2026-11, dan 2027-06 tidak membuat tagihan untuk santri itu; untuk 2027-07 membuat tagihan `BULANAN` berisi item SPP `base_amount` 500.000, `discount_amount` 250.000, `amount` 250.000 dengan `fee_override_id` = keringanan jalur. Skenario `travelTo('2027-08-15')`: periode 2027-08 tanpa tagihan, 2027-09 bertagihan. Tidak ada bentrok unique dengan tagihan PSB yang kini ber-`student_id`.

**Wali**

- AC-19: Tanpa wali cocok → satu `guardians` baru (`name` "Budi Santoso", `phone` `081234567890`), `student_guardian` `relation` sesuai input (bawaan `wali` bila tidak dikirim), `is_billing_contact` true; `guardian_reused` false.
- AC-20: Wali lama "budi  santoso " ber-`phone` `+6281234567890` yang terhubung ke kakak (santri lain, `relation` `ayah`, kontak tagihan kakak) → dipakai ulang (`guardian_reused` true, jumlah `guardians` tetap, data wali lama tidak berubah), terhubung ke santri baru dengan relation input dan `is_billing_contact` true untuk santri baru; pivot kakak tidak berubah. Varian `6281234567890` juga cocok.
- AC-21: Wali lama dengan nomor sama tetapi nama berbeda ("Siti Aminah"), atau nama sama dengan nomor `0812-3456-7890` → tidak dipakai ulang, wali baru dibuat. Dua wali cocok → yang id-nya terkecil dipakai. Input `relation` `paman` → `relation_invalid` tanpa perubahan.

**Dokumen**

- AC-22: Dokumen hasil salin (AC-9): `document_type_id` = jenis syarat, `documentable_type` `student`, `documentable_id` santri, `status` `valid`, `verified_by`/`verified_at` sama dengan berkas PSB, `uploaded_by` = A, `original_name`, `mime_type`, `size_kb` sama, `number`/`issued_on`/`expires_on`/`replaced_by_id` NULL, `source_applicant_document_id` = id berkas PSB; file ada di disk `private` di bawah `documents/student/{student_id}/` dengan isi byte sama dengan sumber dan path berbeda; file sumber dan baris `applicant_documents` tidak berubah. `CheckDocumentCompleteness` santri (KK dan AKTA wajib) mengembalikan daftar kosong.
- AC-23: Syarat berjenis owner `wali` (mis. jenis `KTP_WALI` dibuat di test) dengan berkas `valid` → disalin sebagai dokumen `guardian` milik wali hasil konversi (`documents/guardian/{guardian_id}/`). Syarat berjenis owner `staff` → dilewati `owner_not_supported`. Berkas `valid` yang filenya hilang dari disk → dilewati `file_missing`, `Log::warning` tercatat, konversi tetap berhasil, dan `documents_skipped` memuat keduanya. Disk `private` palsu yang `copy()`-nya mengembalikan `false` → daftar ulang gagal `file_copy_failed`, tanpa santri, dokumen, maupun file salinan (kompensasi berjalan).
- AC-24: Activity `dokumen` per dokumen yang disalin: event `copied_from_applicant`, causer A, properti `document_type`, `documentable_type`, `documentable_id`, `source_applicant_document_id`, `status`; tidak ada properti `number`, `file_path`, `original_name` dan nama file tidak muncul di JSON properti.

**Idempotensi, atomik, status**

- AC-25: Daftar ulang kedua untuk P (instance basi maupun dimuat ulang) → `already_converted`; jumlah `students`, `guardians`, `student_guardian`, `documents`, file di disk, dan activity tidak bertambah. Santri hasil konversi lalu di-soft-delete → daftar ulang tetap `already_converted`.
- AC-26: Status `diajukan`, `diverifikasi`, `cadangan`, `ditolak` → `applicant_not_accepted` (dataset); pendaftar terhapus → `applicant_not_found`; tanpa perubahan.
- AC-27: Atomik: dengan binding `CopyApplicantDocuments` palsu (subkelas) yang menjalankan penyalinan induk lalu melempar `RuntimeException` → exception diteruskan; tidak ada santri, angkatan baru, wali baru, `student_guardian`, dokumen, maupun activity `psb`/`keuangan`/`dokumen` baru; `student_id` tagihan dan keringanan P tetap NULL; semua file yang sempat disalin terhapus dari disk (disk `private` hanya berisi file sumber). Varian: penghapusan salah satu file kompensasi melempar exception (disk palsu) → exception asli (`RuntimeException` dari subkelas) tetap yang diteruskan dan `Log::warning` berisi path tercatat.
- AC-28: Percobaan ulang NIS: binding `GenerateStudentNumber` palsu yang pertama mengembalikan NIS santri yang sudah ada lalu NIS baru → berhasil dengan NIS baru, satu santri, dan tidak ada file yatim dari percobaan pertama. Palsu yang selalu mengembalikan NIS terpakai → `RuntimeException` (`student_number_conflict`) setelah 3 percobaan, `Log::error`, tanpa baris atau file tersisa.

**Audit**

- AC-29: Setelah AC-9: tepat satu activity `psb` event `converted` (subject P, causer A) dengan properti `student_id`, `nis`, `guardian_id`, `guardian_reused`, `invoices` 3, `fee_overrides` 2, `documents_copied` 2; tepat satu activity `keuangan` event `applicant_billing_transferred` (subject santri, causer A) dengan `applicant_id`, `invoice_ids`, `fee_override_ids` terurut; tidak ada activity `keuangan` otomatis lain dari pemindahan; properti kedua activity tidak memuat nama, NISN, maupun nomor telepon.

**Penjaga Action lain**

- AC-30: Setelah konversi: `GenerateAcceptanceInvoices::handle(P, A)` dan `ApplyTrackScholarship::handle(P, A)` → `applicant_converted` tanpa tagihan atau keringanan baru; `SaveFeeOverride::handle(['applicant_id' => P, ...])` (create) → `applicant_converted` (`pondok.keuangan.errors`); `RejectApplicant` (batalkan penerimaan) dan `DeleteApplicant` → `applicant_converted`, termasuk setelah santri di-soft-delete. Mengedit keringanan P yang sudah ada tetap bisa.

**Panel dan halaman publik**

- AC-31: `RolePermissionSeeder`: `re-register:applicant` ada; `admin-psb` dan `super-admin` memilikinya; `bendahara`, `admin-sekolah`, `kesantrian` tidak (dataset `grants each role exactly its permissions` diperbarui); `ApplicantPolicy::reRegister` memeriksa permission itu.
- AC-32: `ViewApplicant` sebagai A: aksi `reRegister` tampil untuk P (`diterima`, belum konversi); tersembunyi untuk status `diverifikasi`, untuk P setelah konversi, dan untuk user tanpa `re-register:applicant` (super-admin dengan permission dicabut atau peran kustom). Form terbuka dengan `entered_on` bawaan `2027-07-01` dan `relation` bawaan `wali` (memanggil aksi tanpa mengubah `relation` → pivot `wali`). Mengisi form (`relation` `ibu`) dan memanggil aksi → santri dibuat, notifikasi success memuat NIS, aksi hilang setelah dimuat ulang. Pembayaran kurang → notifikasi danger berisi pesan `min_payment_not_met`, tidak ada santri. `entered_on` terlalu awal → error di field `entered_on`.
- AC-33: Seksi "Daftar ulang" `ViewApplicant`: sebelum konversi menampilkan "Rp 4.000.000 dari Rp 8.000.000 (50%)", "50%", dan badge "Memenuhi syarat"; dengan pembayaran 0 badge "Belum memenuhi"; setelah konversi badge "Sudah daftar ulang" dan "NIS — nama" sebagai tautan ke edit santri bagi super-admin, teks tanpa tautan bagi A (tanpa permission santri). Aksi F011 "Buat tagihan penerimaan" tersembunyi setelah konversi walau ada grup `saat_diterima` tanpa tagihan.
- AC-34: Cakupan: `admin-psb` bercakupan SLTP menjalankan daftar ulang P (SLTP) lewat panel → berhasil dan santri baru terlihat di `Student::query()` user itu; pendaftar SD tidak bisa dibuka (404, aturan F010) sehingga aksinya tidak bisa dipanggil.
- AC-35: `ListApplicants` filter "Daftar ulang" `true` hanya menampilkan pendaftar yang sudah menjadi santri (termasuk yang santrinya terhapus), `false` hanya pendaftar `diterima` yang belum.
- AC-36: `ApplicationStatus` publik untuk P setelah konversi menampilkan "Daftar ulang selesai" beserta NIS; sebelum konversi menampilkan kalimat `status_help.diterima` F011.
- AC-37: Semua kunci `lang/id` baru di bagian Teks ada dan tidak tampil sebagai kunci mentah (dataset, pola F010/F011 `translates every ... text key`).

**Tambahan review #1**

- AC-38: Wali lama yang dipakai ulang (AC-20) sudah punya dokumen current `KTP_WALI` berstatus `valid`; P punya berkas `valid` untuk syarat `KTP_WALI` → berkas itu dilewati `owner_has_current`, dokumen wali lama tidak berubah (`replaced_by_id` tetap NULL, status tetap), jumlah dokumen current wali untuk jenis itu tetap 1, tidak ada file salinan untuk berkas itu; berkas owner `santri` tetap disalin. Bila dokumen lama wali sudah diganti (punya `replaced_by_id`) dan tidak ada versi current jenis itu → berkas disalin.
- AC-39: Keringanan pendaftar dan konversi: (a) instance P dimuat, lalu keringanan baru untuk P dibuat lewat `SaveFeeOverride`, lalu `handle(P basi)` → keringanan itu ikut mendapat `student_id` dan masuk `fee_override_ids` activity; (b) setelah konversi, `SaveFeeOverride` create dengan `applicant_id` P → `applicant_converted` walau pemanggil memegang instance P yang dimuat sebelum konversi; tidak ada keringanan ber-`applicant_id` P dengan `student_id` NULL. (c) `ReRegisterApplicant` memakai `whereNull('student_id')`: dengan subkelas/listener test yang mengisi `student_id` sebuah tagihan P ke santri lain tepat sebelum langkah 7 → `billing_transfer_mismatch`, seluruh percobaan di-rollback (tagihan itu tetap milik santri lain, tidak ada santri baru).
- AC-40: Unique `students.applicant_id`: `Schema::getIndexes('students')` memuat unique [`applicant_id`] dan index `unit_id`+`status`, `nis` unique, `nisn` unique tetap ada; FK `students` (`unit_id`, `cohort_id`, `applicant_id`) tetap ada; membuat dua santri ber-`applicant_id` sama lewat factory → `UniqueConstraintViolationException`; banyak santri ber-`applicant_id` NULL diterima. Konversi bersamaan disimulasikan dengan santri ber-`applicant_id` P disisipkan di sela pembacaan (binding `GenerateStudentNumber` palsu yang menyisipkan baris itu) → `already_converted`, tanpa santri kedua dan tanpa file tersisa.

## Rencana test

Konvensi `it('AC-n: ...')`. Helper baru di bagian terpisah akhir `tests/Pest.php` ("Helper daftar ulang PSB (F012)"; periksa `git diff` dulu karena F009–F013 menyunting file yang sama): `daftarUlangFixture(): object` (di atas `penerimaanFixture()`, menambah `UANG_PANGKAL`, `SERAGAM`, `SPP` dan tarifnya), `daftarUlangApplicant(object $f, array $attributes = [], int $paid = 4_000_000, string $unit = 'SLTP'): Applicant` (pendaftar `diterima` lewat `penerimaanAccept()`, formulir lunas, tagihan penerimaan dibayar `$paid` lewat `RecordPayment`), `daftarUlangValidDocument(Applicant $a, string $code, string $status = 'valid'): ApplicantDocument` (file nyata di `Storage::fake('private')`), `daftarUlangRun(Applicant $a, array $data = [], ?User $by = null): array`, `daftarUlangAssertRejected(Closure $cb, string $reason, ?string $field = null, string $group = 'psb')`.

| AC | File | Nama test (usulan) |
|---|---|---|
| 1–3 | `tests/Feature/Database/DocumentSourceForeignKeyTest.php` | `AC-1: links documents to their admission source`, `AC-2: keeps documents indexes and foreign keys after the rebuild`, `AC-3: survives rollback and migrate with data on sqlite` |
| 4 | `tests/Feature/Database/Seeders/SettingSeederTest.php` (tambah) | `AC-4 (F012): seeds re-registration settings without overriding` |
| 5–8 | `tests/Feature/Actions/Psb/CheckReRegistrationPaymentTest.php` | `AC-5: requires the minimum paid percent`, `AC-6: reads the configured percent` (dataset), `AC-7: counts only active acceptance invoices`, `AC-8: handles missing invoices and the finance switch` |
| 9–11, 25–29 | `tests/Feature/Actions/Psb/ReRegisterApplicantTest.php` | `AC-9: converts an applicant with invoices, overrides, and documents`, `AC-10: leaves other applicants untouched`, `AC-11: makes moved billing visible to the student`, `AC-25: refuses a second conversion`, `AC-26: requires an accepted applicant` (dataset), `AC-27: rolls back and removes copied files on failure`, `AC-28: retries a colliding student number`, `AC-29: records one psb and one finance activity` |
| 12, 16, 17 | `tests/Feature/Actions/Psb/ReRegisterApplicantStudentDataTest.php` | `AC-12: finds or creates the cohort`, `AC-16: rejects a taken nisn`, `AC-17: sets the entry date to next month or the school year start` |
| 13–15 | `tests/Feature/Actions/Akademik/GenerateStudentNumberTest.php` | `AC-13: numbers students by entry year`, `AC-14: follows the configured format` (dataset format tidak valid), `AC-15: accepts a manual number` |
| 18 | `tests/Feature/Actions/Psb/ReRegisterMonthlyBillingTest.php` | `AC-18: starts monthly billing from the entry month` |
| 19–21 | `tests/Feature/Actions/Psb/ResolveApplicantGuardianTest.php` | `AC-19: creates a guardian`, `AC-20: reuses a sibling guardian`, `AC-21: avoids merging different guardians` |
| 22–24 | `tests/Feature/Actions/Psb/CopyApplicantDocumentsTest.php` | `AC-22: copies valid documents to the student`, `AC-23: routes guardian documents and skips unsupported ones`, `AC-24: logs copies without personal data` |
| 30 | `tests/Feature/Actions/Psb/ConvertedApplicantGuardsTest.php` | `AC-30: blocks billing changes on a converted applicant` |
| 31 | `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (ubah) | dataset `admin-psb`, `AC-31: creates the re-register permission` |
| 32–35 | `tests/Feature/Filament/Resources/Applicants/ReRegisterApplicantActionTest.php` | sesuai AC |
| 36 | `tests/Feature/Livewire/Psb/ApplicationStatusTest.php` (tambah) | `AC-36 (F012): shows the student number after re-registration` |
| 37 | `tests/Feature/Filament/PsbAccessTest.php` (tambah) | `translates every re-registration text key` (dataset) |
| 38 | `tests/Feature/Actions/Psb/CopyApplicantDocumentsTest.php` | `AC-38: keeps the current guardian document` |
| 39 | `tests/Feature/Actions/Psb/ConvertedApplicantGuardsTest.php` | `AC-39: never leaves applicant overrides behind` |
| 40 | `tests/Feature/Database/StudentApplicantUniqueTest.php` | `AC-40: allows one student per applicant` |

**Penyesuaian test lama (red-tester, mode RED; review #1 #2):** `tests/Feature/Database/DokumenConstraintsTest.php` test F013 `AC-1: creates the documents table from the ERD` mengassert FK `source_applicant_document_id` **tidak** ada. Setelah migration F012, assert itu diubah menjadi: FK `source_applicant_document_id` → `applicant_documents.id` **ada** dengan `on_delete` `restrict`/`no action` (case-insensitive); assert FK `document_type_id`, `verified_by`, `uploaded_by`, `replaced_by_id`, ketiadaan FK `documentable_id`, index, dan kolom lain tidak dilemahkan. Green-coder tidak menyentuh test itu. Bila F010/F011 menguji `applicant_converted` lewat `student()` saja, tetap lulus (perilaku hanya diperluas ke santri terhapus).

**Eksekusi suite penuh:** setelah test terkait lulus, jalankan `php artisan test --parallel --processes=3 --compact` (batas 3 proses karena RAM mesin 8 GB).

Catatan untuk red-tester: AC-2/AC-3 hanya dijalankan pada koneksi SQLite (`skip` bila bukan), membandingkan `Schema::getIndexes`/`getForeignKeys` sebelum dan sesudah. AC-27/AC-28 memakai `app()->bind()` subkelas/palsu. AC-29 dan AC-24 memakai `actingAs(A)` dan `$by` = A. Test F011/F010 yang mengassert `applicant_converted` lewat `student()` disesuaikan bila perlu (perilakunya hanya diperluas ke santri terhapus).

## Urutan implementasi

1. Migration FK `documents.source_applicant_document_id` + relasi; AC-1–3; penyesuaian test F013 AC-1 sudah dilakukan red-tester.
1a. Migration unique `students.applicant_id`; AC-40 (bagian skema).
2. `SettingSeeder` (dua key); AC-4.
3. `Applicant::convertedStudent()`/`isConverted()`; `CheckReRegistrationPayment` + kunci error; AC-5–8.
4. `GenerateStudentNumber`, `FindOrCreateCohort` + kunci error; AC-13–14 (sebagian AC-12).
5. `ResolveApplicantGuardian`; AC-19–21.
6. `CopyApplicantDocuments` (termasuk `owner_has_current`, `copy()` false); AC-22–24, AC-38.
7. `ReRegisterApplicant` (normalisasi, loop percobaan, kompensasi file, activity); AC-9–12, AC-15–17, AC-25–29, AC-39 (c), AC-40 (konversi bersamaan).
8. AC-18 (generator, tanpa perubahan kode F006; bila gagal, perbaiki `entered_on`, bukan generator).
9. Penjaga Action lain (`GenerateAcceptanceInvoices`, `ApplyTrackScholarship`, `SaveFeeOverride` dengan kunci baris pendaftar, `RejectApplicant`, `DeleteApplicant`); AC-30, AC-39 (a, b).
10. `RolePermissionSeeder` + `ApplicantPolicy::reRegister`; AC-31.
11. Teks `lang/id/pondok.php` (UI); design guide §3/§4.
12. Aksi `reRegisterAction`, seksi infolist, aksi F011 tersembunyi, filter `ListApplicants`; AC-32–35.
13. `ApplicationStatus` kalimat setelah konversi; AC-36.
14. Sinkronkan `docs/erd.md` (commit `docs(psb)`): FK `source_applicant_document_id`, unique `students.applicant_id` di tabel constraint, arti `students.entered_on` (bulan pertama tagihan bulanan; kalimat "Tagihan bulanan dimulai bulan berikutnya" di tabel Biaya PSB diperjelas menjadi "mulai bulan `entered_on`, paling cepat bulan berikutnya, bawaan awal tahun ajaran bila belum mulai"), berkas wali yang dilewati bila wali sudah punya versi current, settings `psb.min_paid_percent` (basis = tagihan penerimaan aktif, bawaan 50) dan `students.nis_format`, angkatan dibuat otomatis dari tahun masuk gelombang, pencocokan wali, semua tagihan/keringanan pendaftar (status apa pun) mendapat `student_id`, owner berkas `wali` disalin ke wali, catatan "Saat status menjadi diterima ... membuat students" dipastikan sudah diganti F011.
15. Pint, test terkait (AC-37 + test F010/F011/F013 yang disentuh), checklist design guide §0 (cek visual modal daftar ulang dan seksi infolist di desktop, `/psb/status` 360px).

Gerbang: langkah 1–10 hijau (AC tanpa UI) sebelum langkah 11.

## Risiko & pertanyaan terbuka

Setiap pertanyaan punya default yang dipakai bila user tidak memutuskan lain.

1. **Basis persen `psb.min_paid_percent`.** *Default:* jumlah `paid_total` dibanding jumlah `total` tagihan **penerimaan** aktif (`saat_diterima`); formulir tidak dihitung (sudah wajib lunas sebelum diverifikasi, F011), tagihan `batal` tidak dihitung, total 0 = 100%. Alternatif: semua tagihan PSB aktif (formulir menaikkan persen sedikit).
2. **Nilai bawaan `psb.min_paid_percent`.** *Default:* 50 (contoh ERD; uang pangkal memang boleh dicicil dan "tunggakan uang pangkal terlihat di profil santri"). Alternatif 100 (wajib lunas).
3. **Format NIS (PRD 7 terbuka).** *Default:* setting `students.nis_format` bawaan `{YYYY}{NNNN}` (mis. `20270001`, urut per tahun masuk lintas jenjang), token `{YYYY}`, `{YY}`, `{UNIT}`, urutan `{N…}` di akhir; panitia juga bisa mengisi NIS manual saat daftar ulang. Menunggu format resmi Ma'had Al-Kitab.
4. **`entered_on` sebagai awal penagihan.** *Default:* `max(awal bulan depan, awal tahun ajaran gelombang)`, bisa diubah panitia tetapi tidak lebih awal dari awal bulan depan (ERD "Tagihan bulanan dimulai bulan berikutnya"). Tidak ada perubahan pada generator F006 (sudah menyaring `entered_on`). Akibat: santri berstatus `aktif` sejak daftar ulang walau baru masuk Juli. Dampak yang ditinjau (review #1 #10): `PromoteStudents` hanya memproses santri yang punya `enrollments` di rombel asal, sehingga santri baru tanpa rombel tidak terpengaruh; widget dan hitungan "santri aktif per jenjang/angkatan" (F018) serta `FinanceReport` ikut menghitungnya sejak daftar ulang (diterima; angkatan baru memang sudah "terdaftar"); `SaveFeeOverride` untuk santri (`student_not_active`) menerimanya. Santri tidak bisa ditagih bulan berjalan dari panel. Ini menyimpang dari bacaan harfiah ERD "Tagihan bulanan dimulai bulan berikutnya" untuk daftar ulang sebelum tahun ajaran mulai; ERD diperjelas di langkah 14. Alternatif: kolom baru `billing_starts_on` atau bawaan selalu awal bulan depan (santri ditagih SPP sebelum tahun ajaran mulai), tidak dipilih.
5. **Pencocokan wali.** *Default:* nomor HP (varian `08`/`62`/`+62`) **dan** nama sama (tanpa beda huruf besar/spasi) → wali lama dipakai, datanya tidak diubah; selain itu wali baru. Wali ganda bisa digabung manual nanti (tidak ada fitur gabung; usulan entri baru bila perlu). Hubungan wali (`ayah`/`ibu`/`wali`) dipilih panitia, bawaan `wali`, karena form PSB tidak menanyakannya.
6. **Peran yang menjalankan daftar ulang.** *Default:* `admin-psb` (panitia) lewat permission baru `re-register:applicant`; Action membuat santri, wali, dokumen, dan memindahkan data keuangan sebagai langkah sistem tanpa permission modul lain (pola F011 Risiko #9), semuanya tercatat di activity log. `admin-sekolah` bisa diberi lewat Shield. Alternatif: `admin-sekolah` saja (memisahkan panitia dari data santri).
7. **Tagihan mana yang pindah.** *Default:* semua tagihan dan keringanan pendaftar, termasuk yang `lunas`, `batal`, dan keringanan yang sudah berakhir (riwayat lengkap di profil santri; ERD "Tagihan dan keringanan yang belum selesai ikut pindah" adalah himpunan bagiannya). `applicant_id` tetap.
8. **Penempatan rombel dan kamar.** *Default:* di luar F012; dilakukan setelah daftar ulang lewat fitur F002/F003. Santri baru dianggap nonmukim sampai punya kamar aktif, jadi biaya `mukim` baru tertagih setelah penempatan (generator membaca kamar aktif saat berjalan).
9. **Satu salinan per berkas PSB.** *Default:* dijaga Action (kunci baris pendaftar + `already_converted` + cek `source_applicant_document_id`) dan unique `students.applicant_id` (satu konversi per pendaftar), tanpa unique pada `documents.source_applicant_document_id` (mengubah index ke unique memperumit perombakan tabel `documents`). Keterbatasan F009: satu jenis dokumen satu syarat per jenjang, sehingga pemetaan berkas → jenis dokumen langsung.
10. **Atomik dengan file.** *Default:* file disalin di dalam percobaan transaksi dan dihapus bila percobaan gagal (kompensasi; kegagalan hapus hanya di-log dan tidak menimpa exception asli). Proses yang mati di antara salin dan commit meninggalkan file yatim tak terjangkau di `documents/`; diterima (tidak ada data pribadi yang terbuka, hanya ruang disk). I/O file terjadi selama kunci baris pendaftar dan tagihannya dipegang; diterima untuk disk lokal (beberapa berkas ≤ 2 MB). Dengan S3/R2 opsional, kunci bisa tertahan beberapa detik; bila menjadi masalah, penyalinan dipindah ke sebelum transaksi (perubahan desain, entri baru).
11. **Santri internal yang mendaftar ke jenjang berikutnya.** *Default:* tidak ditangani; NISN bentrok ditolak dengan pesan yang mengarahkan ke perpindahan jenjang (`ChangeStudentUnit`). Tagihan PSB pendaftar seperti itu tetap milik pendaftar. Usulan entri baru "psb-santri-internal".
12. **Owner berkas `wali`.** *Diputuskan orkestrator (review #1 #3):* disalin ke wali hasil konversi; bila wali (yang dipakai ulang) sudah punya dokumen current dengan jenis sama, berkas dilewati `owner_has_current` dan dokumen lama tidak disentuh (tidak membuat rantai versi otomatis). Owner `staff`/`prestasi` dilewati.
13. **Tidak ada pembatalan daftar ulang.** Salah daftar ulang diperbaiki manual (ubah status santri `keluar`, data keuangan tetap). Usulan entri baru bila dibutuhkan.
14. **Pengaturan tanpa UI.** `psb.min_paid_percent` dan `students.nis_format` hanya bisa diubah lewat tabel `settings` (tinker/phpMyAdmin) sampai ada halaman pengaturan. Usulan entri baru "inti-pengaturan-modul".
15. **Dependensi belum `done`.** F010, F011, F013 masih rencana; F012 memakai nama kontrak dari rencana APPROVED. Bila implementasi final berbeda (nama helper test, `acceptanceInvoices()`, `Document::OWNER_TO_MORPH`), orkestrator menyesuaikan rencana ini sebelum RED.
16. **Retensi berkas PSB (UU PDP).** Setelah disalin, berkas sumber di `psb/applicants/` tetap tersimpan, sehingga ada dua salinan KK/akta. *Default:* tidak dihapus di F012 (baris `applicant_documents` dirujuk FK `restrict` dan menjadi jejak asal); masuk usulan entri "psb-retensi-data" (F010 Risiko #21) agar kebijakan retensi/anonimisasi mencakup berkas pendaftar yang sudah menjadi santri.
17. **Pemanggilan di dalam transaksi luar.** `ReRegisterApplicant` mengasumsikan transaksinya sendiri (loop percobaan di luar transaksi). Dicatat di PHPDoc; pemanggil baru (mis. daftar ulang massal) tidak boleh membungkusnya dalam `DB::transaction`.
18. **Usulan entri baru** (tidak dikerjakan di F012): "psb-retensi-data" (lihat #16), "inti-pengaturan-modul" (halaman pengaturan `psb.min_paid_percent`, `students.nis_format`, `keuangan.due_day`), "psb-santri-internal" (menautkan pendaftar ke santri yang sudah ada), opsional "akademik-tab-tagihan-santri" (tab tagihan di halaman santri), opsional "akademik-gabung-wali" (menggabungkan wali ganda).

## Riwayat revisi

- DRAFT 1 (product-manager): rencana awal. Jumlah AC: 37.
- DRAFT 2 (product-manager, Review #1 + keputusan orkestrator): major #1 — `SaveFeeOverride` create dengan `applicant_id` mengunci baris pendaftar lalu cek `isConverted()`; langkah 7 `ReRegisterApplicant` memindahkan id yang dikunci dengan `whereNull('student_id')` dan membandingkan jumlah baris (`billing_transfer_mismatch`); AC-39 baru (keringanan sebelum/sesudah konversi, instance basi, mismatch). #2 — penyesuaian test F013 AC-1 (`DokumenConstraintsTest.php`, FK `source_applicant_document_id` kini harus ada) dimasukkan ke Rencana test dan langkah 1. #3 (keputusan orkestrator) — berkas wali dilewati `owner_has_current` bila wali sudah punya dokumen current jenis sama; AC-38 baru, kunci `copy_skip_reasons.owner_has_current`, Risiko #12 diganti. Minor: `copy()` false → `file_copy_failed` (rollback + kompensasi; AC-23/AC-27); kompensasi hapus file dengan try/catch + log path (AC-27), I/O di dalam kunci dicatat (Risiko #10); migration unique `students.applicant_id` + penanganan unique violation → `already_converted` (AC-40) dan asumsi tanpa transaksi luar (Action, Risiko #17); `GenerateStudentNumber` memakai `pluck('nis')`; AC-15 `address_too_long`, AC-17 batas hari terakhir bulan, AC-32 `relation` bawaan `wali`; Risiko #4 mencatat penyimpangan dari kalimat ERD dan dampak ke `PromoteStudents`/widget/laporan, langkah 14 memperjelas ERD; Risiko #16 retensi berkas PSB (usulan "psb-retensi-data"); rekomendasi `php artisan test --parallel --processes=3 --compact` untuk suite penuh. Jumlah AC: 40.

## Review #1 — 2026-10-16

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | Perubahan Action yang sudah ada (`SaveFeeOverride`), AC-30 | Penjaga `applicant_converted` di `SaveFeeOverride` hanya membaca status "sudah menjadi santri", padahal `SaveFeeOverride` tidak mengunci baris pendaftar (`app/Actions/Keuangan/SaveFeeOverride.php` hanya `lockForUpdate` pada baris keringanan yang tumpang tindih, baris ~98). Keringanan baru untuk pendaftar yang dibuat di sela langkah 7 (UPDATE `fee_overrides`) dan commit `ReRegisterApplicant` lolos penjaga dan tersisa dengan `student_id` NULL selamanya, sehingga beasiswa/potongan SPP tidak pernah sampai ke santri (CLAUDE.md PSB: "student_id diisi pada ... override milik pendaftar"). F011 sudah menutup celah yang sama untuk `GenerateAcceptanceInvoices` lewat kunci baris pendaftar (Review F011 #2); `SaveFeeOverride` belum. | Saat membuat keringanan dengan `applicant_id`, buka transaksi yang diawali `Applicant::withoutGlobalScopes()->withTrashed()->lockForUpdate()->find()`, cek `isConverted()` pada kondisi terkunci itu. Tambah AC: setelah konversi, keringanan baru untuk pendaftar ditolak walau instance pendaftar basi; dan pemindahan di langkah 7 memakai `whereNull('student_id')` serta jumlah baris yang diperbarui dibandingkan dengan id yang dikunci/dicatat. |
| 2 | major | Rencana test, FK `documents.source_applicant_document_id`; F013 AC-1 | Rencana F013 AC-1 menegaskan `Schema::getForeignKeys('documents')` **tidak** memuat FK `source_applicant_document_id`. Begitu migration F012 masuk, test F013 itu gagal, sedangkan green-coder dilarang mengubah test dan tabel pemetaan F012 hanya menyebut penyesuaian test F010/F011. | Tambahkan ke Rencana test dan catatan red-tester: test F013 AC-1 diubah (red-tester) agar tidak mengassert ketiadaan FK itu, tanpa melemahkan assert FK lain. Sebutkan nama file test F013 yang disentuh. |
| 3 | major | `CopyApplicantDocuments`, Risiko #12, AC-23 | Berkas owner `wali` disalin ke wali yang dipakai ulang tanpa `replaced_by_id`, sehingga wali itu bisa punya dua dokumen "current" untuk jenis yang sama. Ini melanggar aturan riwayat CLAUDE.md ("dokumen (`replaced_by_id`)") dan invarian F013 (`current()` = `replaced_by_id IS NULL`; F013 AC-18: satu versi current per jenis dan pemilik; `ReplaceDocument` mengasumsikan satu current). Risiko #12 menyebut ini sebagai konsekuensi yang diterima, padahal merusak data dokumen wali kakak yang sudah diverifikasi. | Bila pemilik (wali reuse) sudah punya dokumen current sejenis, lewati dengan `skipped` alasan baru `owner_has_current` (dokumen lama tidak disentuh) atau rantai versi lewat `replaced_by_id`; pilih satu, tambahkan AC (wali reuse dengan KTP_WALI valid yang sudah ada) dan kunci `copy_skip_reasons`. Hapus kalimat penerimaan di Risiko #12. |
| 4 | minor | `CopyApplicantDocuments` langkah 4 | `Storage::disk('private')->copy()` mengembalikan `false` (bukan exception) bila disk tidak diset `throw`. Rencana tidak menangani; baris `documents` bisa dibuat menunjuk file yang tidak ada. | Periksa hasil `copy()`: `false` lalu lempar exception (rollback + kompensasi) atau `skipped copy_failed`. Tambah ke AC-23/AC-27 satu kasus disk palsu yang gagal menyalin. |
| 5 | minor | `ReRegisterApplicant` kegagalan/kompensasi | Kompensasi menghapus file setelah rollback; bila `delete` sendiri melempar, exception asli tertimpa. Penyalinan file (bisa lambat di S3 opsional) terjadi saat kunci baris pendaftar dan tagihan dipegang. | Bungkus penghapusan dengan try/catch + `Log::warning` (path saja, bukan data santri) agar exception asli diteruskan; catat di Risiko bahwa I/O file di dalam kunci diterima untuk disk lokal. |
| 6 | minor | Model data, `students.applicant_id` | ERD menggambarkan relasi satu-ke-satu (`applicants |o--o| students`) tetapi `students.applicant_id` hanya index biasa (`create_students_table`), sehingga satu-satunya penjaga konversi ganda adalah kunci baris (no-op di SQLite, dan tidak melindungi jalur lain seperti `CreateStudent` dengan `applicant_id` manual). | Pertimbangkan unique `students.applicant_id` pada migration FK F010 (atau jelaskan di Risiko mengapa tidak). Catat bahwa loop percobaan di luar transaksi mengasumsikan `ReRegisterApplicant` tidak dipanggil di dalam transaksi pemanggil. |
| 7 | minor | `GenerateStudentNumber` | Kandidat diambil dengan `LIKE prefiks%` lalu dicocokkan di PHP; dengan format bawaan seluruh santri berawalan tahun ikut termuat. | Ambil hanya kolom `nis` (`pluck`) dan hitung maksimum di PHP. |
| 8 | minor | Kualitas AC | Tidak ada AC untuk `address` > 1000 karakter (`address_too_long`), bawaan `relation` = `wali` pada form UI, maupun `entered_on` pada hari terakhir bulan (batas tanggal). | Tambahkan ke AC-15/AC-17/AC-32 satu kasus masing-masing. |
| 9 | minor | Dependensi | `depends_on` F011 dan F013 masih `todo` (F010 di rantai F011); F012 belum diklaim di `feature_list.json`. Dipahami sebagai rancangan di atas rencana APPROVED dengan gerbang RED. | Pertahankan gerbang: RED hanya setelah ketiganya `done` dan nama kontrak (`acceptanceInvoices()`, `OWNER_TO_MORPH`, helper test) diverifikasi ulang. |
| 10 | minor | `entered_on` bawaan vs ERD, UU PDP | Bawaan Juli 2027 untuk daftar ulang Oktober 2026 menyimpang dari kalimat ERD "Tagihan bulanan dimulai bulan berikutnya" (default Q4, langkah 14 menyinkronkan ERD). Santri `aktif` ber-`entered_on` masa depan ikut hitungan aktif/dashboard dan Action lain yang membaca `status`. Berkas sumber PSB tetap tersimpan setelah disalin (dua salinan data pribadi, tanpa aturan retensi). | Pastikan sinkronisasi ERD memuat arti baru `entered_on` dan tinjau dampaknya ke `PromoteStudents`/widget santri aktif; catat retensi berkas sumber sebagai usulan entri baru. |

Sudah diperiksa dan baik: seluruh acceptance F012 tercakup; tanpa tabel baru; uang `unsignedBigInteger` dan perbandingan persen bilangan bulat; snapshot tagihan tidak diubah, data keuangan tidak dihapus; `entered_on` dipakai generator F006 yang sudah menyaringnya (diverifikasi di `GenerateMonthlyInvoices`); unique invoices tidak bentrok (tagihan PSB beda grup, `batal` ber-`active_marker` NULL); retry di luar transaksi aman untuk Postgres; wali tidak digabung bila ragu; log tanpa PII; permission `re-register:applicant` konsisten dengan pola Shield; query portabel; tanpa Redis/Node; test wajib CLAUDE.md untuk konversi (tagihan, override, dokumen) terpetakan (AC-9, AC-22, AC-29).

## Review #2 — 2026-10-16

Verdict: APPROVED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Verifikasi Review #1 | Semua temuan terselesaikan dan terpetakan: major #1 (`SaveFeeOverride` mengunci baris pendaftar, `whereNull('student_id')` + `billing_transfer_mismatch`, AC-39), major #2 (penyesuaian `DokumenConstraintsTest.php` F013 AC-1 masuk Rencana test dan langkah 1), major #3 (`owner_has_current`, AC-38, Risiko #12), serta minor `file_copy_failed`, kompensasi try/catch, unique `students.applicant_id` (AC-40), `pluck('nis')`, tambahan AC-15/17/32, catatan ERD/`entered_on` dan retensi. Tidak ada blocker atau major baru. | - |
| 2 | minor | Unique `students.applicant_id` | Penanganan `UniqueConstraintViolationException` harus membedakan pelanggaran `applicant_id` dari `nis`/`nisn` (cek ulang `isConverted()` di luar transaksi, bukan menebak dari pesan DB, agar portabel di SQLite/MySQL/Postgres). Migration ini juga harus bertimestamp setelah migration FK `applicant_id` F010 agar urutan rebuild SQLite aman. | Catatan untuk green-coder; AC-40 sudah menguji hasilnya. |
| 3 | minor | Dependensi | F010, F011, F013 masih belum `done`; gerbang RED di rencana tetap berlaku (verifikasi ulang nama kontrak sebelum RED). | Orkestrator menjaga gerbang dan mengklaim F012 di `feature_list.json` saat RED dimulai. |

