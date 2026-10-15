# F013 dokumen-repositori — Repositori dokumen

Status rencana: APPROVED

Owner: diklaim orkestrator di `feature_list.json`. Dependensi tertulis: F002 (`done`). **Dependensi nyata tambahan:** tabel `document_types`, model `DocumentType`, factory, dan `DocumentTypeSeeder` dibuat oleh F009 (rencana APPROVED, implementasi sedang berjalan). Red-tester tidak boleh mulai sebelum migration `document_types` F009 ada di repo (lihat Risiko #1).

## Ringkasan & tujuan

Satu repositori dokumen untuk santri, wali, dan staff (prestasi menyusul di F015) dengan:

- **Jenis dokumen** yang bisa diatur admin (`document_types`, tabel dari F009; F013 menambah resource, Action, permission, dan policy).
- **Dokumen** (`documents`) polymorphic: unggah berkas ke disk `private`, nomor KK/NIK terenkripsi, tanggal terbit dan masa berlaku, status verifikasi `menunggu | valid | ditolak`.
- **Riwayat versi**: unggah versi baru menambah baris; baris lama ditandai `replaced_by_id`, tidak pernah ditimpa atau dihapus.
- **Kerahasiaan**: berkas dan nomor dokumen yang jenisnya `is_confidential` hanya bisa dibuka pemilik permission `view-confidential:document`.
- **Cakupan jenjang**: dokumen santri (dan wali, lewat santrinya) mengikuti `unit_user`.
- **Kelengkapan berkas wajib**: Action + scope query yang bisa dipakai ulang oleh dashboard (F018) dan portal wali (Fase 2).
- **Unduh** lewat route panel yang dicek modul, cakupan, dan permission.

Acuan: PRD 2 (Admin Sekolah: dokumen), PRD 3.6 butir 1–3; ERD "Dokumen" (`document_types`, `documents`, catatan satu tabel polymorphic, kelengkapan, riwayat versi, data pribadi), tabel index ERD (`documents`), ERD "PSB" (`applicant_documents`, untuk kolom `source_applicant_document_id`); design guide §4, §5 (NIK/No. KK dimasker), §6, §7, §8, §9.

## Ruang lingkup

- Termasuk:
  - Migration `documents` + model `Document` + factory.
  - Morph map yang bisa diperluas: `student`, `guardian`, `staff` (F015 menambah `achievement`).
  - Relasi `documents(): MorphMany` di `Student`, `Guardian`, `Staff`; relasi `documents(): HasMany` di `DocumentType`.
  - Action di `app/Actions/Dokumen/`: `SaveDocumentType`, `UploadDocument`, `ReplaceDocument`, `VerifyDocument`, `CheckDocumentCompleteness`.
  - Scope `Student::withIncompleteDocuments()` / `withCompleteDocuments()`; scope `Document::current()`, `Document::expired()`.
  - Global scope cakupan jenjang pada `Document` (`HasUnitScope` + `applyUnitScope` khusus).
  - Permission + policy `DocumentType` dan `Document` (aksi tambahan `verify`, `viewConfidential`), pembagian ke peran.
  - Saklar `modules.dokumen.enabled`, ditambahkan ke `SettingSeeder::ENABLED_BY_DEFAULT`.
  - UI Filament: `DocumentTypeResource` (Manage modal), `DocumentResource` (daftar + verifikasi, tanpa create/edit/delete), `DocumentsRelationManager` dipasang di `StudentResource`, `GuardianResource`, `StaffResource`; filter "Berkas wajib" di daftar santri.
  - Route unduh `GET /admin/documents/{document}/download` + controller.
  - Penjaga hapus wali dan staff yang punya dokumen (relasi polymorphic tidak punya FK).
  - Activitylog (log `dokumen`) untuk unggah, versi baru, dan verifikasi, tanpa nomor dokumen.
  - Teks `lang/id/pondok.php`, navigasi grup "Dokumen", pembaruan tabel status §4 design guide.
- Tidak termasuk (eksplisit):
  - `issued_letters`, surat keluar, QR verifikasi (F014).
  - Model `Achievement`, pemilik `prestasi`, dan unggah sertifikat/foto prestasi (F015). F013 hanya menyiapkan titik perluasan.
  - Penyalinan berkas PSB ke `documents` dan FK `source_applicant_document_id → applicant_documents` (F010/F012). F013 hanya membuat kolomnya tanpa FK.
  - Widget dashboard kelengkapan dokumen (F018) dan pengingat di portal wali (Fase 2). F013 hanya menyediakan Action dan scope.
  - Pengeditan metadata dokumen, penghapusan dokumen, dan penghapusan berkas fisik. Koreksi = versi baru atau status `ditolak`.
  - Catatan alasan penolakan (kolom tidak ada di ERD; lihat Pertanyaan #4).
  - Log akses/unduh dokumen rahasia (lihat Pertanyaan #6).
  - Pengubahan `DocumentTypeSeeder` (milik F009).

## Model data

### Morph map

`Document::DOCUMENTABLE_TYPES = ['student' => Student::class, 'guardian' => Guardian::class, 'staff' => Staff::class]` didaftarkan di `AppServiceProvider::boot()` dengan `Relation::morphMap(...)` (**bukan** `enforceMorphMap`, karena Spatie Permission dan Activitylog menyimpan `App\Models\User` sebagai `model_type`/`causer_type` dan akan gagal bila map dipaksakan).

`Document::OWNER_TO_MORPH = ['santri' => 'student', 'wali' => 'guardian', 'staff' => 'staff', 'prestasi' => 'achievement']` memetakan `document_types.owner` ke alias morph. Owner yang aliasnya belum ada di `DOCUMENTABLE_TYPES` (saat ini `prestasi`) tidak bisa dipakai untuk unggah. F015 cukup menambah satu entri ke `DOCUMENTABLE_TYPES` dan satu cabang di `applyUnitScope`.

Saat ini tidak ada tabel yang menyimpan morph class `Student`/`Guardian`/`Staff` (diverifikasi: ketiganya tidak memakai `LogsActivity`, tidak punya notifikasi/media), sehingga mendaftarkan alias tidak mengubah data lama.

### `document_types` (dari F009, tanpa perubahan skema)

Kolom: `code` (unique, string 30), `name`, `owner` (`santri|wali|staff|prestasi`), `is_required`, `has_expiry`, `is_confidential`, `is_active`. F013 menambah ke model: relasi `documents(): HasMany`, konstanta/akses yang dipakai Action. Tanpa activitylog (keputusan F009 #9).

### `documents` — model `App\Models\Document`

Migration `..._create_documents_table` (timestamp setelah migration F009 terakhir).

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| document_type_id | foreignId → document_types, `restrictOnDelete` | |
| documentable_type | string(50) | alias morph (`student`, `guardian`, `staff`) |
| documentable_id | unsignedBigInteger | tanpa FK (polymorphic) |
| number | **text** nullable | cast `encrypted`; ciphertext Laravel ±200+ karakter sehingga `string(255)` tidak aman |
| issued_on | date nullable | |
| expires_on | date nullable | index |
| file_path | string(255) | relatif terhadap disk `private`, folder `documents/{alias}/{documentable_id}/`, nama acak (`hashName`) |
| original_name | string(255) | nama asli dari klien, dipotong 255 |
| mime_type | string(100) | MIME hasil deteksi server |
| size_kb | unsignedInteger | `ceil(bytes / 1024)`, minimal 1 |
| status | enum `menunggu`, `valid`, `ditolak` default `menunggu` | pola enum seperti `payments.status` |
| verified_by | foreignId nullable → users, `restrictOnDelete` | |
| verified_at | timestamp nullable | |
| uploaded_by | foreignId → users, `restrictOnDelete` | wajib (ERD) |
| replaced_by_id | foreignId nullable → documents, `restrictOnDelete`, **unique** | satu dokumen baru hanya menggantikan satu dokumen lama; NULL boleh banyak di MySQL/Postgres/SQLite |
| source_applicant_document_id | unsignedBigInteger nullable, index | **tanpa FK** sampai `applicant_documents` ada (F010/F012) |
| timestamps | | |

Index: `documents_owner_type_index` pada (`documentable_type`, `documentable_id`, `document_type_id`) (ERD), (`expires_on`) (ERD), (`status`) untuk antrean verifikasi. Tanpa soft delete. Tidak ada kolom `deleted_at`.

Model:
- `#[Fillable]` semua kolom kecuali `id`/timestamps. Konstanta `STATUS_PENDING = 'menunggu'`, `STATUS_VALID = 'valid'`, `STATUS_REJECTED = 'ditolak'`, `STATUSES`, `DISPLAY_REPLACED = 'diganti'`, `DISK = 'private'`, `DIRECTORY = 'documents'`, `MAX_SIZE_KB = 5120`, `MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf']`, `NUMBER_MAX_LENGTH = 50`.
- Cast: `number` → `encrypted`, `issued_on`/`expires_on` → `date`, `verified_at` → `datetime`, `size_kb` → `integer`.
- `$attributes = ['status' => 'menunggu']`.
- Relasi: `documentable(): MorphTo`, `documentType()`, `uploader()` (`uploaded_by`), `verifier()` (`verified_by`), `replacedBy(): BelongsTo` (`replaced_by_id`), `previousVersion(): HasOne` (dokumen yang `replaced_by_id` = id ini).
- Scope `current()`: `replaced_by_id IS NULL`. Scope `expired(?CarbonInterface $today = null)`: `expires_on < today`.
- Accessor `display_status`: `diganti` bila `replaced_by_id` terisi, selain itu `status`. Satu tempat pemetaan warna: `Document::STATUS_COLORS = ['menunggu' => 'warning', 'valid' => 'success', 'ditolak' => 'danger', 'diganti' => 'gray']`.
- `isConfidential(): bool` membaca `documentType->is_confidential` saat ini (kerahasiaan mengikuti jenis, bukan snapshot; mengubah jenis menjadi rahasia langsung melindungi dokumen lama).
- `maskedNumber(): ?string`: 4 karakter pertama + `•` sebanyak sisa − 8 + 4 karakter terakhir; nomor ≤ 8 karakter → seluruhnya `•`. NULL → NULL.
- Trait `HasUnitScope` dengan `applyUnitScope()` khusus (lihat Permission & cakupan).

`Student`, `Guardian`, `Staff`: `documents(): MorphMany` (`morphMany(Document::class, 'documentable')`).

`Student` scope (dipakai F018/portal wali, harus konsisten dengan `CheckDocumentCompleteness`):
- `scopeWithIncompleteDocuments(Builder $query, ?CarbonInterface $today = null)`: ada jenis dokumen `is_required = true`, `is_active = true`, `owner = santri` yang **tidak** punya dokumen `documentable_type = 'student'`, `documentable_id = students.id`, `replaced_by_id IS NULL`, `status = valid`, dan (`expires_on IS NULL` atau `expires_on >= today`). Ditulis dengan `whereExists`/`whereNotExists` (portabel SQLite/MySQL/Postgres), tanpa fungsi khusus database.
- `scopeWithCompleteDocuments(...)`: kebalikannya (`whereNotExists` dari kondisi di atas). Bila tidak ada jenis wajib aktif, semua santri lengkap.

## Action class

Semua Action melempar `ValidationException::withMessages([$field => __("pondok.dokumen.errors.{$reason}")])`, tanpa perubahan DB maupun berkas tersisa saat gagal. Tanggal diparse ketat `Y-m-d` (pola F009: `Carbon::createFromFormat('!Y-m-d')` + cek format ulang) dan dinormalisasi ke string `Y-m-d` sebelum `fill`. Hari ini dibaca dari `today()` dan waktu dari `now()` (zona aplikasi `Asia/Jakarta` dari `config/app.php`, tidak di-hardcode).

### `App\Actions\Dokumen\SaveDocumentType::handle(array $data, ?DocumentType $type = null): DocumentType`

Input: `code`, `name`, `owner`, `is_required?`, `has_expiry?`, `is_confidential?`, `is_active?`.

1. `code` di-trim dan di-uppercase; harus `^[A-Z0-9_]{2,30}$` → `code`/`code_format`. Unik → `code`/`code_taken`. Saat edit, `code` tidak boleh berubah → `code`/`code_locked` (kode dipakai `DocumentTypeSeeder` untuk idempotensi).
2. `name` di-trim, 1–255 → `name`/`name_required`.
3. `owner` ∈ `DocumentType::OWNERS` → `owner`/`owner_invalid`. Saat edit, owner tidak boleh berubah bila jenis sudah punya `documents` atau `admission_requirements` → `owner`/`owner_locked`. Pemeriksaan memakai `Document::withoutUnitScope()` (lihat "Pemeriksaan integritas tanpa cakupan"), sehingga dokumen jenjang di luar cakupan user tetap mengunci owner.
4. `is_required = true` hanya untuk owner `santri` → `is_required`/`required_only_for_student`.
5. Default boolean: `is_required` false, `has_expiry` false, `is_confidential` false, `is_active` true.

Hapus jenis dokumen: `DeleteAction` bawaan Filament, hanya tampil bila jenis tidak punya `documents` dan tidak punya `admission_requirements` (FK `restrictOnDelete` tetap menjadi pengaman terakhir).

### `App\Actions\Dokumen\UploadDocument::handle(Model $documentable, array $data, User $uploader): Document`

`$data`: `array{document_type_id: int|string, file: UploadedFile, number?: string|null, issued_on?: string|null, expires_on?: string|null}`.

Validasi berurutan:
1. Pemilik: kelas `$documentable` harus terdaftar di `Document::DOCUMENTABLE_TYPES` → `documentable`/`owner_not_supported`. Pemilik santri yang di-soft-delete → `documentable`/`owner_archived`. Pemilik harus terlihat oleh cakupan user login: santri lewat `Student::query()` (global scope); wali lewat santri terhubung yang terlihat (bila user bercakupan); staff selalu → `documentable`/`owner_not_found`.
2. `document_type_id` ada → `document_type_id`/`document_type_not_found`; aktif → `document_type_inactive`; `OWNER_TO_MORPH[owner]` sama dengan alias pemilik → `document_type_owner_mismatch`.
3. `file` wajib → `file`/`file_required`; MIME (deteksi server) ∈ `MIME_TYPES` dan ukuran ≤ `MAX_SIZE_KB` → `file`/`file_invalid`.
4. `number`: kosong/whitespace → NULL; di-trim; maks `NUMBER_MAX_LENGTH` karakter → `number`/`number_too_long`. Format bebas (nomor ijazah memuat huruf).
5. `issued_on`: kosong → NULL; tanggal valid → `issued_on`/`date_invalid`; tidak boleh setelah hari ini → `issued_on`/`issued_on_future`.
6. `expires_on`: kosong → NULL; terisi hanya bila jenis `has_expiry` → `expires_on`/`expiry_not_applicable`; tanggal valid → `date_invalid`; bila `issued_on` terisi, `expires_on > issued_on` → `expires_on`/`expires_before_issued`. Masa berlaku boleh kosong walau jenis `has_expiry`.

Efek:
- Simpan berkas ke disk `private`, folder `documents/{alias}/{documentable_id}` (nama acak). Disk `public` tidak pernah dipakai.
- Dalam transaksi: buat baris `documents` (`status` `menunggu`, `uploaded_by` = uploader, `original_name` = nama klien dipotong 255, `mime_type`, `size_kb`), catat activity.
- Bila apa pun gagal setelah berkas tersimpan, berkas dihapus sebelum exception diteruskan (pola F007).
- Selalu membuat baris baru; beberapa dokumen aktif dengan jenis sama untuk pemilik yang sama **diperbolehkan** (mis. rapor per semester, beberapa sertifikat). Mengganti dokumen dilakukan lewat `ReplaceDocument`.

Activity: log `dokumen`, `performedOn($document)`, `causedBy($uploader)`, event/description `uploaded`, properties `['document_type' => code, 'documentable_type' => alias, 'documentable_id' => id, 'status' => 'menunggu']`. **Tidak pernah** memuat `number`, `file_path`, maupun `original_name`.

### `App\Actions\Dokumen\ReplaceDocument::handle(Document $current, array $data, User $uploader): Document`

`$data`: `file`, `number?`, `issued_on?`, `expires_on?` (jenis dan pemilik diwarisi dari `$current`).

1. Baris `$current` dibaca ulang dengan `lockForUpdate` di dalam transaksi; `replaced_by_id` sudah terisi → `document`/`already_replaced`.
2. Pemilik diperiksa seperti `UploadDocument` langkah 1 (`owner_archived`, `owner_not_found`).
3. Jenis dokumen boleh nonaktif (versi baru dari dokumen yang sudah ada tetap diizinkan).
4. Validasi `file`, `number`, `issued_on`, `expires_on` sama dengan `UploadDocument` 3–6.
5. Efek dalam satu transaksi: buat dokumen baru (`status` `menunggu`), set `$current->replaced_by_id` = id baru. Baris lama tidak diubah selain `replaced_by_id` (status, nomor, berkas, verifikator tetap). Berkas lama tidak dihapus dari disk.
6. Pembersihan berkas baru bila gagal (pola sama).

Implementasi berbagi validasi dengan `UploadDocument` (method/private helper bersama atau satu kelas validator internal), bukan menyalin kode.

Activity: satu entri `replaced` pada dokumen baru, properties `['document_type', 'documentable_type', 'documentable_id', 'replaces_id' => $current->id]` tanpa nomor/berkas.

Konsekuensi yang disengaja: dokumen wajib yang `valid` lalu diganti versi baru `menunggu` membuat kelengkapan berstatus `pending` sampai versi baru diverifikasi.

### `App\Actions\Dokumen\VerifyDocument::handle(Document $document, string $status, User $verifier): Document`

1. `$status` ∈ {`valid`, `ditolak`} → `status`/`status_invalid`.
2. Dokumen sudah diganti (`replaced_by_id` terisi) → `status`/`document_replaced`.
3. Status sama dengan status saat ini → `status`/`status_unchanged` (mencegah log kosong). Perpindahan `menunggu → valid|ditolak`, `valid → ditolak`, dan `ditolak → valid` diizinkan (koreksi salah verifikasi).
4. Bila `status = valid` dan jenis `has_expiry` dan `expires_on < hari ini` → `status`/`document_expired` (dokumen kedaluwarsa tidak bisa divalidasi; minta versi baru).
5. Efek: `status`, `verified_by`, `verified_at = now()`; activity `verified` dengan properties `['from' => lama, 'to' => baru]`.

Action tidak memeriksa permission (dicek policy/UI), sesuai pola Action lain.

### `App\Actions\Dokumen\CheckDocumentCompleteness::handle(Student $student, ?CarbonInterface $today = null): array`

Mengembalikan `list<array{document_type: DocumentType, reason: 'missing'|'pending'|'expired'|'rejected'}>`, urut `document_types.name`. Daftar kosong = lengkap.

Untuk setiap jenis `is_required`, `is_active`, owner `santri`, lihat dokumen **current** santri dengan jenis itu:
- ada yang `valid` dan (`expires_on` NULL atau ≥ hari ini) → lengkap (tidak masuk daftar);
- selain itu, ada yang `menunggu` → `pending`;
- selain itu, ada yang `valid` tetapi kedaluwarsa → `expired`;
- selain itu, ada yang `ditolak` → `rejected`;
- tidak ada dokumen current → `missing`.

Query maksimal dua (jenis wajib + dokumen santri), tanpa N+1. Tidak menerapkan global scope dokumen secara berbeda dari santri itu (memakai `Document::withoutUnitScope()` untuk santri yang sudah diberikan, karena pemanggil sudah memegang model santrinya). Tidak memeriksa status santri; pemanggil yang menyaring santri aktif.

## UI (Filament / Blade)

Rujukan: design guide §0 (checklist), §3 (Heroicon Outlined, `$hasTitleCaseModelLabel = false`), §4 (badge status dari `Document::STATUS_COLORS`), §5 (tanggal `j M Y`, tanggal + jam `j M Y, H.i`, kosong `-`, NIK/No. KK dimasker di tabel), §6 (struktur resource, `defaultSort`, eager-load, filter jenjang `accessible()`, aksi lain di `ActionGroup`), §7 (empty state), §8 (konfirmasi, label kata kerja + objek), §9 (pesan error bisa ditindaklanjuti). Panggil `/anti-ui-slop` bila tersedia saat implementasi.

Grup navigasi baru `pondok.navigation.dokumen` = "Dokumen". Resource dokumen memakai `BelongsToModule` (`getModule(): 'dokumen'`) dan `ShowsActionErrorsOnForm`.

### `DocumentTypeResource` — "Jenis dokumen" (`/admin/document-types`, halaman `ManageDocumentTypes` modal, sort 2, ikon `Heroicon::OutlinedRectangleStack`)

- Form (`columns(2)`): `code` (disabled saat edit, helper "Huruf besar, angka, atau garis bawah. Tidak bisa diubah setelah disimpan."), `name`, `owner` (Select dari `pondok.document_type.owners`, disabled saat edit bila sudah dipakai), `is_required` (Toggle, tampil hanya bila owner `santri`, helper "Wajib dimiliki setiap santri aktif."), `has_expiry` (Toggle), `is_confidential` (Toggle, helper "Berkas dan nomornya hanya bisa dibuka peran yang berhak."), `is_active` (Toggle, default true).
- Tabel: `code`, `name`, `owner` (label), `is_required` (teks "Wajib"/"Opsional"), `is_confidential` (IconColumn boolean), `has_expiry` (IconColumn), `is_active` (IconColumn), `documents_count` (toggleable; dihitung tanpa unit scope: `withCount(['documents' => fn ($q) => $q->withoutGlobalScope(UnitScope::class)])`, label "Jumlah dokumen (semua jenjang)"). Default sort `owner`, lalu `name`. Filter owner, `is_active`. Empty state.
- Aksi: Create/Edit lewat `SaveDocumentType` (`saveShowingFormErrors`); `DeleteAction` dengan konfirmasi, tersembunyi bila `documents_exists` atau `admission_requirements_exists` (`withExists`, relasi `documents` **tanpa** unit scope). Field `owner` disabled saat edit bila salah satu ada. Tidak ada bulk delete.

### `DocumentResource` — "Dokumen" (`/admin/documents`, halaman `ListDocuments`, sort 1, ikon `Heroicon::OutlinedDocumentText`)

Antrean verifikasi dan pencarian lintas pemilik. Tanpa halaman create/edit dan tanpa hapus.

- Query: eager-load `documentType`, `documentable` (`morphWith` santri → `unit`), `uploader`, `verifier`. Default sort `created_at` desc.
- Kolom: `documentType.name` (pengenal), nama pemilik (`documentable.name`; santri dengan NIS sebagai deskripsi), jenis pemilik (label `pondok.document.owner_types.*`), jenjang (santri saja, `-` untuk lainnya), nomor (dimasker; lihat aturan nomor), `expires_on`, `display_status` (badge), `created_at` (toggleable), `uploader.name` (toggleable), `verifier.name`/`verified_at` (toggleable).
- Filter: `status` (SelectFilter, **default `menunggu`**), jenis dokumen, jenis pemilik, jenjang (`Unit::accessible()`, menyaring dokumen santri lewat `whereHasMorph`), "Versi terbaru saja" (TernaryFilter default true → `current()`), "Kedaluwarsa" (TernaryFilter → `expired()`).
- Pencarian: nama pemilik (`whereHasMorph` ke `student`/`guardian`/`staff` kolom `name`, plus `students.nis`). Tidak bisa mencari nomor dokumen (terenkripsi).
- Aksi baris (`ActionGroup`): "Lihat detail" (modal infolist), "Unduh berkas" (URL ke route unduh, tab baru), "Verifikasi dokumen", "Unggah versi baru". Aturan tampil di bawah.
- Empty state: "Tidak ada dokumen yang menunggu verifikasi." (saat filter bawaan) / umum "Belum ada dokumen."

### `DocumentsRelationManager` — "Dokumen" (satu kelas, dipasang di `StudentResource`, `GuardianResource`, `StaffResource`)

- `canViewForRecord`: modul `dokumen` aktif **dan** user `viewAny` Document. Saat modul mati, tab tidak muncul (halaman pemilik tetap terbuka).
- Deskripsi tabel khusus santri: hasil `CheckDocumentCompleteness` → "Berkas wajib lengkap." atau "Berkas wajib belum lengkap: Kartu Keluarga (belum diunggah), Akta kelahiran (menunggu verifikasi)." (alasan dari `pondok.document.completeness_reasons.*`).
- Kolom: jenis, nomor (dimasker), `issued_on`, `expires_on`, `display_status` (badge), `created_at`, `uploader.name` (toggleable). Default sort `created_at` desc. Filter "Versi terbaru saja" default true, status.
- Header action "Unggah dokumen" (`primary`): form `document_type_id` (Select: jenis aktif dengan owner yang cocok; jenis rahasia hanya muncul bila user punya `view-confidential:document`), `file` (`FileUpload`, `storeFiles(false)`, `acceptedFileTypes` jpg/png/pdf, `maxSize(MAX_SIZE_KB)`, wajib), `number` (helper "Isi nomor KK, NIK, atau nomor dokumen bila ada."), `issued_on`, `expires_on` (tampil bila jenis `has_expiry`). Sebelum memanggil Action, handler aksi memeriksa ulang di server: jenis yang dikirim harus termasuk opsi yang boleh untuk user (`Gate::allows('uploadType', [Document::class, $type])`: jenis rahasia butuh `view-confidential:document`) → bila tidak, error `document_type_id`/`confidential_not_allowed` di field (tanpa baris dan berkas). Sama untuk "Unggah versi baru" pada dokumen rahasia. Lalu memanggil `UploadDocument` lewat `saveShowingFormErrors`. Notifikasi sukses "Dokumen :type untuk :owner diunggah dan menunggu verifikasi."
- Aksi baris sama dengan `DocumentResource` (berbagi definisi lewat static method di `DocumentResource`: `downloadAction()`, `verifyAction()`, `replaceAction()`, `viewAction()`).

### Aturan tampil aksi dan data (dicek di policy, dipakai di UI dan route)

| Hal | Syarat |
|---|---|
| Baris dokumen terlihat | `view-any:document` + cakupan jenjang + jenis pemilik boleh (`Document::visibleOwnerTypesFor`) |
| Unduh berkas, nomor tampil penuh di detail, `original_name` | policy `download`: `view:document` dan (jenis tidak rahasia atau `view-confidential:document`) |
| Nomor di tabel | `maskedNumber()` bila boleh `download`; selain itu teks `pondok.document.confidential` ("Rahasia") untuk dokumen rahasia atau `-` bila nomor kosong |
| Unggah dokumen / versi baru | policy `create`: `create:document`; pemilik bukan santri terarsip; untuk jenis rahasia juga `view-confidential:document`; versi baru hanya untuk dokumen current |
| Verifikasi | policy `verify`: `verify:document` dan boleh `download` (verifikator harus bisa melihat berkasnya); hanya dokumen current |

Aksi "Verifikasi dokumen": modal dengan Radio `status` (`valid` "Valid", `ditolak` "Ditolak"), deskripsi "Dokumen yang ditolak perlu diunggah ulang sebagai versi baru.", memanggil `VerifyDocument`. Aksi "Unggah versi baru": deskripsi "Dokumen lama tetap tersimpan sebagai riwayat dan ditandai diganti." (`requiresConfirmation` lewat modal form), memanggil `ReplaceDocument`.

### Perubahan resource lain

- `StudentsTable`: TernaryFilter "Berkas wajib" (`true` → `withCompleteDocuments`, `false` → `withIncompleteDocuments`, label "Lengkap"/"Belum lengkap"), tampil hanya bila `Setting::moduleEnabled('dokumen')`.
- `GuardiansTable` + `EditGuardian`, `StaffTable`(bila ada aksi hapus) + `EditStaff`: `DeleteAction` tersembunyi bila pemilik punya dokumen, dihitung **tanpa** unit scope (`withExists(['documents' => fn ($q) => $q->withoutGlobalScope(UnitScope::class)])` di tabel; `$record->documents()->withoutGlobalScope(UnitScope::class)->exists()` di halaman edit). Santri hanya soft delete (tidak ada force delete di UI), jadi tidak perlu penjaga.

### Teks `lang/id/pondok.php` (kunci baru)

`navigation.dokumen`; `document_type.{label, plural, code, code_helper, name, owner, owners.{santri,wali,staff,prestasi}, is_required, is_required_helper, required_labels.{1,0}, has_expiry, is_confidential, is_confidential_helper, is_active, documents_count, empty, delete_description}`; `document.{label, plural, document_type, owner, owner_types.{student,guardian,staff}, number, number_helper, issued_on, expires_on, file, original_name, mime_type, size_kb, status, statuses.{menunggu,valid,ditolak,diganti}, uploaded_by, uploaded_at, verified_by, verified_at, confidential, current_only, expired_filter, completeness_filter, completeness_labels.{1,0}, complete, incomplete (:items), completeness_reasons.{missing,pending,expired,rejected}, upload_action, uploaded (:type, :owner), replace_action, replace_description, replaced (:type), verify_action, verify_description, verified (:type, :status), download_action, view_action, empty, empty_pending, activity_uploaded, activity_replaced, activity_verified}`; `dokumen.errors.{code_format, code_taken, code_locked, name_required, owner_invalid, owner_locked, required_only_for_student, owner_not_supported, owner_archived, owner_not_found, document_type_not_found, document_type_inactive, document_type_owner_mismatch, file_required, file_invalid, number_too_long, date_invalid, issued_on_future, expiry_not_applicable, expires_before_issued, already_replaced, status_invalid, document_replaced, status_unchanged, document_expired, confidential_not_allowed}`. Kunci `dokumen.errors.*` ditulis **bersama** Action yang memakainya (langkah 4–8 urutan implementasi), bukan di akhir; `confidential_not_allowed` ditulis bersama aksi unggah UI (langkah 12). Setiap pesan menyebut cara memperbaiki (§9), mis. `file_invalid`: "Unggah berkas JPG, PNG, atau PDF dengan ukuran paling besar 5 MB." Tidak ada teks UI hardcode.

### Design guide §4

Tabel status diperbarui (dokumen, satu commit `docs(dokumen)`): `Dokumen | menunggu | warning`, `Dokumen | valid | success` (sudah ada), `Dokumen | ditolak | danger`, `Dokumen | diganti (dihitung dari replaced_by_id) | gray` (sudah ada). Baris `Dokumen | perlu revisi | warning` diganti dengan `menunggu` karena ERD tidak memiliki status "perlu revisi" (Pertanyaan #5).

## Permission & cakupan

### Permission baru

`RolePermissionSeeder::RESOURCE_MODELS` ditambah `DocumentType` dan `Document`. `EXTRA_RESOURCE_ACTIONS['Document'] = ['verify', 'viewConfidential']` → `verify:document`, `view-confidential:document`. Policy dibuat dengan `php artisan shield:generate` untuk kedua resource, lalu `DocumentPolicy` ditambah method `verify(User, Document)`, `download(User, Document)`, `viewConfidential(User)`, dan `uploadType(User, DocumentType)` (jenis rahasia butuh `view-confidential:document`); `view`/`download`/`verify` juga memeriksa jenis pemilik sesuai tabel aturan di atas (pola `PaymentPolicy::cancel`). Test "every permission that a generated policy checks" harus tetap lulus.

### Pembagian ke peran

| Peran | DocumentType | Document |
|---|---|---|
| super-admin | semua | semua (termasuk `update`, `delete`, `delete-any` yang tidak dipakai UI) |
| admin-sekolah | semua 6 aksi | `view-any`, `view`, `create`, `verify`, `view-confidential` |
| kesantrian | — | `view-any`, `view` (hanya dokumen santri, tanpa rahasia; perlu kartu BPJS dll. untuk asrama) |
| admin-psb, bendahara, admin-konten, musyrif, ustadz-tahfidz, petugas-pos, wali | — | — |

Tidak ada peran selain super-admin yang mendapat `update:document`, `delete:document`, `delete-any:document`. Dataset `grants each role exactly its permissions` di `RolePermissionSeederTest` diperbarui untuk `admin-sekolah` dan `kesantrian` (perhatikan file ini juga sedang diubah F008: tambahkan baris, jangan menimpa perubahan `view:finance-report`).

### Cakupan jenjang

`Document` memakai `HasUnitScope` dengan `applyUnitScope(Builder $builder, array $unitIds)`:

```
(documentable_type = 'student' AND documentable_id IN (Student::withoutGlobalScopes()->whereIn('unit_id', $unitIds)->select('id')))
OR (documentable_type = 'guardian' AND documentable_id IN (student_guardian.guardian_id untuk student_id di atas))
OR (documentable_type = 'staff')
```

- Santri yang di-soft-delete tetap ikut (pola `Payment`).
- Wali terlihat bila terhubung ke minimal satu santri dalam cakupan; wali tanpa santri tidak terlihat oleh user bercakupan.
- Staff tidak berjenjang; dari sisi cakupan jenjang terlihat semua (pembatasan per peran lewat aturan jenis pemilik di bawah).
- Tipe yang tidak dikenal (mis. `achievement` sebelum F015 memperluas) **tidak terlihat** oleh user bercakupan (fail-closed).
- Route model binding unduh memakai query dengan global scope → di luar cakupan 404.

### Jenis pemilik yang boleh dilihat per user (keputusan orkestrator, review #1 #3)

Selain cakupan jenjang, dokumen dibatasi menurut jenis pemiliknya:

| Jenis pemilik | Syarat tambahan selain `view-any:document`/`view:document` |
|---|---|
| `student` | tidak ada |
| `guardian` | `update:guardian` (pengelola data wali) |
| `staff` | `update:staff` (pengelola data staff) |
| lainnya (mis. `achievement` sebelum F015) | hanya super-admin |

Diterapkan di dua tempat yang memakai satu sumber `Document::visibleOwnerTypesFor(User $user): list<string>` (super-admin → semua alias di `DOCUMENTABLE_TYPES` ditambah tipe tak dikenal; lainnya sesuai tabel):
- `DocumentPolicy::view`/`download`/`verify`/`create` (untuk pemilik tertentu) menolak bila alias pemilik tidak termasuk;
- query `DocumentResource` (`whereIn('documentable_type', ...)`, super-admin tanpa filter) dan filter "Jenis pemilik" hanya menawarkan jenis yang boleh.
Hasilnya `kesantrian` (punya `view:guardian` tetapi tidak `update:guardian`, tanpa permission staff) hanya melihat dokumen santri. Ini bukan global scope (bergantung permission, bukan jenjang) agar Action dan pemeriksaan integritas tidak terpengaruh. F015 menambah aturan untuk `achievement`.

### Pemeriksaan integritas tanpa cakupan (review #1 #1)

Global scope jenjang `Document` juga berlaku pada relasi `documents()` (`MorphMany`/`HasMany`) dan `withExists`/`withCount`. Karena itu **setiap pemeriksaan "masih punya dokumen" untuk integritas data** wajib mengabaikan scope (`Document::withoutUnitScope()` atau `->withoutGlobalScope(UnitScope::class)` pada relasi):
- `SaveDocumentType` `owner_locked`;
- penjaga `DeleteAction` dan `documents_count` di `DocumentTypeResource`;
- penjaga hapus `GuardiansTable`, `EditGuardian`, `EditStaff` (dan `StaffTable` bila punya aksi hapus);
- `CheckDocumentCompleteness` dan scope kelengkapan `Student` (santri sudah dipegang pemanggil).
Tampilan daftar dan unduhan tetap memakai scope. Helper bersama yang disarankan: `HasDocuments::hasAnyDocuments(): bool` (trait pada `Student`/`Guardian`/`Staff`) dan `DocumentType::isInUse(): bool`, keduanya tanpa scope.

### Tab dokumen wali di luar cakupan (review #1 #2)

`Guardian` tidak punya global scope, jadi admin bercakupan melihat semua wali, sedangkan dokumen wali hanya terlihat bila wali terhubung ke santri dalam cakupan. *Keputusan:* `DocumentsRelationManager::canViewForRecord` untuk pemilik wali bernilai false bila user bercakupan dan wali tidak terhubung ke santri mana pun dalam cakupannya (termasuk wali baru tanpa santri). User tanpa cakupan selalu melihat tab. Wali baru perlu dihubungkan ke santri dulu sebelum diberi dokumen oleh admin bercakupan.

### Saklar modul

`modules.dokumen.enabled` (sudah ada di `SettingSeeder::MODULES`) ditambahkan ke `ENABLED_BY_DEFAULT` (F009 menambah `psb` di baris yang sama; gabungkan, jangan menimpa). Modul mati → menu Dokumen dan Jenis dokumen hilang, URL resource 403, route unduh 403 (middleware `EnsureModuleIsEnabled:dokumen` sebelum binding), tab dokumen di halaman santri/wali/staff tidak tampil, filter "Berkas wajib" tidak tampil.

### Route unduh

`AdminPanelProvider::authenticatedRoutes`: grup `Route::middleware(EnsureModuleIsEnabled::class.':dokumen')` berisi `Route::get('documents/{document}/download', DownloadDocument::class)->name('dokumen.download')`. Controller `App\Http\Controllers\Dokumen\DownloadDocument` (invokable): `Gate::authorize('download', $document)`; `file_path` kosong atau berkas tidak ada di disk `private` → 404; respons `Storage::disk('private')->download($path, "{code lowercase}-{id}.{ext}", ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'])` (lampiran). Nama unduhan sengaja tidak memuat nama santri atau nama asli berkas. `DocumentResource::downloadUrl(Document)` menjadi satu sumber URL.

## Kriteria penerimaan (bisa dites)

Fixture bersama (helper `dokumen*` ditambahkan di **akhir** `tests/Pest.php`, bagian terpisah): `UnitSeeder` (SD, SLTP, SLTA), `DocumentTypeSeeder` (dari F009: KK dan AKTA wajib + rahasia; IJAZAH, RAPOR, FOTO; BPJS `has_expiry`; SERTIFIKAT owner `prestasi`), `RolePermissionSeeder`, `SettingSeeder`, santri SD `S1` dan SLTP `S2`, wali `G1` terhubung ke `S1`, staff `T1`, `Storage::fake('private')` dan `Storage::fake('public')`, waktu dibekukan `2026-10-15 10:00` Asia/Jakarta. User `admin-sekolah` tanpa cakupan `A` kecuali disebut lain.

Skema & model
- AC-1: Setelah migration, `documents` punya kolom ERD (`document_type_id`, `documentable_type`, `documentable_id`, `number`, `issued_on`, `expires_on`, `file_path`, `original_name`, `mime_type`, `size_kb`, `status`, `verified_by`, `verified_at`, `uploaded_by`, `replaced_by_id`, `source_applicant_document_id`) dan tidak punya `deleted_at`; `Document` tidak memakai `SoftDeletes`. `Schema::getIndexes('documents')` memuat index dengan kolom tepat [`documentable_type`, `documentable_id`, `document_type_id`], index [`expires_on`], dan unique [`replaced_by_id`]. `Schema::getForeignKeys('documents')` memuat FK `document_type_id`, `verified_by`, `uploaded_by`, `replaced_by_id` dengan `on_delete` `restrict` (case-insensitive) dan **tidak** memuat FK untuk `source_applicant_document_id` maupun `documentable_id`.
- AC-2: Constraint: menghapus jenis dokumen yang dirujuk dokumen → `QueryException`; menghapus dokumen yang dirujuk `replaced_by_id` dokumen lain → `QueryException`; dua dokumen dengan `replaced_by_id` sama → `UniqueConstraintViolationException`; dua dokumen dengan `replaced_by_id` NULL → keduanya tersimpan; `status` bawaan `menunggu`.
- AC-3: Enkripsi: dokumen dengan `number` `3201234567890001` → nilai mentah kolom (`DB::table('documents')->value('number')`) tidak sama dan tidak memuat `3201234567890001`; `$document->fresh()->number` = `3201234567890001`. Nomor 50 karakter tersimpan dan terbaca utuh (kolom cukup panjang). `number` NULL tetap NULL.
- AC-4: Morph map: `(new Student)->getMorphClass()` = `student`, `Guardian` = `guardian`, `Staff` = `staff`; dokumen milik `S1` tersimpan dengan `documentable_type` `student` dan `$document->documentable` adalah instance `S1`. `S1->documents`, `G1->documents`, `T1->documents` hanya memuat dokumen masing-masing. `(new User)->getMorphClass()` tetap `App\Models\User` (map tidak dipaksakan).
- AC-5: `maskedNumber()`: `3201234567890001` → `3201••••••••0001`; `12345678` → `••••••••`; NULL → NULL. `display_status`: dokumen dengan `replaced_by_id` terisi → `diganti` walau `status` `valid`; selain itu sama dengan `status`. `Document::STATUS_COLORS` memetakan `menunggu` warning, `valid` success, `ditolak` danger, `diganti` gray.

Seeder & modul
- AC-6: Instalasi baru (`settings` kosong lalu `SettingSeeder`) → `Setting::moduleEnabled('dokumen')` true; `modules.dokumen.enabled` yang sudah `false` tidak ditimpa seed ulang; modul lain yang sudah aktif bawaan tetap aktif.
- AC-7: `RolePermissionSeeder` membuat `view-any:document-type` … `delete-any:document-type` (6) dan `view-any:document`, `view:document`, `create:document`, `update:document`, `delete:document`, `delete-any:document`, `verify:document`, `view-confidential:document`. Dataset "grants each role exactly its permissions" diperbarui: `admin-sekolah` mendapat 6 permission jenis dokumen + `view-any:document`, `view:document`, `create:document`, `verify:document`, `view-confidential:document`; `kesantrian` mendapat `view-any:document`, `view:document`; peran lain tidak berubah. Tidak ada peran selain super-admin yang punya `update:document`, `delete:document`, `delete-any:document` (dataset semua peran). Test policy-permission tetap lulus.

SaveDocumentType
- AC-8: Create `{code: ' kip ', name: 'Kartu Indonesia Pintar', owner: 'santri'}` → tersimpan dengan `code` `KIP`, `is_required`/`has_expiry`/`is_confidential` false, `is_active` true. Create dengan `is_required` true owner `santri` berhasil.
- AC-9: Gagal tanpa baris baru (dataset): code `K` (terlalu pendek), `KK-BARU` (tanda hubung), 31 karakter → `code`/`code_format`; `KK` (sudah ada dari seeder) → `code`/`code_taken`; name `"  "` → `name`/`name_required`; owner `ortu` → `owner`/`owner_invalid`; `is_required` true untuk owner `wali` → `is_required`/`required_only_for_student`.
- AC-10: Edit: mengubah `name` dan `is_confidential` jenis `IJAZAH` berhasil; mengubah `code` → `code`/`code_locked`; mengubah owner jenis tanpa dokumen dan tanpa syarat PSB berhasil; mengubah owner `KK` yang punya dokumen → `owner`/`owner_locked`; mengubah owner jenis yang dirujuk `admission_requirements` (factory F009) → `owner_locked`. Login `admin-sekolah` bercakupan SLTP: jenis `IJAZAH` yang dokumennya hanya milik `S1` (SD) → ubah owner tetap `owner_locked`.

UploadDocument
- AC-11: `A` mengunggah KK untuk `S1` dengan `UploadedFile::fake()->create('kk.pdf', 300, 'application/pdf')`, `number` `' 3201234567890001 '`, `issued_on` `2020-01-10` → satu dokumen: `documentable` `S1`, `status` `menunggu`, `uploaded_by` `A`, `number` `3201234567890001` (di-trim), `original_name` `kk.pdf`, `mime_type` `application/pdf`, `size_kb` 300, `file_path` berawalan `documents/student/{S1.id}/`, berkas ada di disk `private`, disk `public` kosong, `verified_by`/`verified_at`/`replaced_by_id` NULL.
- AC-12: Unggah kedua dengan jenis sama (RAPOR) untuk pemilik yang sama → dua dokumen current, dokumen pertama tidak berubah.
- AC-13: Gagal tanpa baris baru **dan disk `private` kosong** (dataset): jenis 999 → `document_type_id`/`document_type_not_found`; jenis nonaktif → `document_type_inactive`; jenis `SERTIFIKAT` (owner prestasi) untuk santri → `document_type_owner_mismatch`; KK (owner santri) untuk `G1` → `document_type_owner_mismatch`; tanpa berkas → `file`/`file_required`; berkas `.gif`, `.txt`, atau PDF 5121 KB → `file`/`file_invalid` (PDF tepat 5120 KB berhasil di kasus terpisah); `number` 51 karakter → `number`/`number_too_long`; `issued_on` `2026-02-30` → `issued_on`/`date_invalid`; `issued_on` `2026-10-16` → `issued_on`/`issued_on_future`; `expires_on` diisi untuk KK (tanpa masa berlaku) → `expires_on`/`expiry_not_applicable`; BPJS dengan `issued_on` `2026-01-01` dan `expires_on` `2026-01-01` → `expires_on`/`expires_before_issued`.
- AC-14: BPJS dengan `expires_on` `2027-12-31` berhasil; BPJS tanpa `expires_on` berhasil (`expires_on` NULL).
- AC-15: Pemilik: unggah untuk model `User` (tidak terdaftar) → `documentable`/`owner_not_supported`; untuk `S1` yang di-soft-delete → `documentable`/`owner_archived`; login `admin-sekolah` bercakupan SLTP: unggah untuk `S1` (SD) → `documentable`/`owner_not_found`, untuk `G1` (wali santri SD saja) → `owner_not_found`, untuk `S2` dan `T1` berhasil. Pada setiap penolakan disk `private` kosong.
- AC-16: Pembersihan: bila penyimpanan baris gagal setelah berkas tersimpan (mis. `uploaded_by` user yang tidak ada di DB, atau exception yang dipicu di listener `creating`), berkas yang sempat tersimpan dihapus dari disk `private` dan tidak ada baris baru.
- AC-17: Activitylog: satu unggah menghasilkan tepat satu activity log `dokumen`, `subject` dokumen itu, `causer` `A`, description `uploaded`, properties memuat `document_type` `KK`; seluruh JSON properties **tidak** memuat `3201234567890001`, `file_path`, maupun `kk.pdf`.

ReplaceDocument
- AC-18: KK `D1` milik `S1` berstatus `valid` (diverifikasi `A`). `ReplaceDocument(D1, file baru, number '3201234567899999')` → dokumen baru `D2` (`status` `menunggu`, jenis dan pemilik sama dengan `D1`, `uploaded_by` user); `D1.replaced_by_id` = `D2.id`; `D1.status` tetap `valid`, `D1.number`, `D1.file_path`, `D1.verified_by`, `D1.verified_at` tidak berubah dan berkas `D1` masih ada di disk; jumlah dokumen bertambah satu; `S1->documents()->current()` hanya memuat `D2` untuk jenis KK.
- AC-19: Mengganti `D1` lagi setelah AC-18 → `document`/`already_replaced`, tanpa baris baru, disk tidak bertambah berkas. Mengganti `D2` → `D3`, rantai `D1 → D2 → D3` tercatat (`D2.replaced_by_id` = `D3.id`).
- AC-20: Versi baru tetap diizinkan bila jenis dokumennya sudah dinonaktifkan; ditolak untuk santri yang di-soft-delete (`owner_archived`) dan untuk santri di luar cakupan (`owner_not_found`); validasi berkas/nomor/tanggal sama dengan AC-13 (dataset singkat: berkas `.txt` → `file_invalid`, disk tidak bertambah).
- AC-21: Activity: tepat satu entri `replaced` per penggantian dengan `replaces_id` = `D1.id`, tanpa nomor lama/baru di properties.

VerifyDocument
- AC-22: `D` `menunggu` → verify `valid` oleh `A` → `status` `valid`, `verified_by` `A`, `verified_at` = sekarang; activity `verified` dengan `from` `menunggu`, `to` `valid`. `valid → ditolak` dan `ditolak → valid` berhasil dan memperbarui `verified_by`/`verified_at`.
- AC-23: Gagal tanpa perubahan (dataset): status `menunggu` atau `batal` → `status`/`status_invalid`; dokumen yang sudah diganti → `status`/`document_replaced`; verify `valid` pada dokumen yang sudah `valid` → `status_unchanged`; BPJS dengan `expires_on` `2026-10-14` diverifikasi `valid` → `document_expired` (verifikasi `ditolak` untuk dokumen itu berhasil).

Kelengkapan
- AC-24: `CheckDocumentCompleteness(S1)` tanpa dokumen → dua butir, `KK` dan `AKTA`, keduanya `missing` (urut nama: "Akta kelahiran", "Kartu Keluarga").
- AC-25: Dataset alasan untuk KK `S1` (AKTA valid): hanya `menunggu` → `pending`; hanya `ditolak` → `rejected`; `valid` → KK tidak masuk daftar; dokumen KK `valid` lama yang sudah diganti oleh versi `menunggu` → `pending`; KK `ditolak` current + KK `valid` current lain → lengkap. Untuk jenis wajib dengan `has_expiry` (dibuat di test): `valid` dengan `expires_on` `2026-10-14` → `expired`; `expires_on` `2026-10-15` → lengkap.
- AC-26: Jenis wajib yang dinonaktifkan atau jenis tidak wajib tidak dihitung; dokumen milik santri lain atau milik wali dengan `document_type_id` sama tidak dihitung untuk `S1`. Bila semua jenis wajib dinonaktifkan → daftar kosong.
- AC-27: Scope konsisten dengan Action: untuk santri `L` (lengkap), `P` (pending), `M` (missing), `E` (expired), `Student::withIncompleteDocuments()->pluck('id')` = [P, M, E] dan `withCompleteDocuments()` = [L] (setelah disaring ke empat santri itu); untuk setiap santri, `withIncompleteDocuments` memuatnya **jika dan hanya jika** `CheckDocumentCompleteness` tidak kosong. Hasil sama saat `today` diberikan eksplisit `2026-10-15`. Jumlah query `CheckDocumentCompleteness` untuk satu santri ≤ 3 (tanpa N+1 bila ada 5 dokumen).

Cakupan & kerahasiaan (model/policy)
- AC-28: Dokumen milik `S1` (SD), `S2` (SLTP), `S1` yang di-soft-delete, `G1`, wali tanpa santri, dan `T1`. Login `admin-sekolah` bercakupan SLTP: `Document::query()` hanya memuat dokumen `S2` dan `T1`. Bercakupan SD: dokumen `S1`, `S1` terarsip, `G1`, `T1`. Tanpa cakupan: semua. Baris dengan `documentable_type` `achievement` (dibuat langsung via `DB::table`) tidak terlihat oleh user bercakupan dan terlihat oleh user tanpa cakupan.
- AC-29: Policy (dataset peran × dokumen KK rahasia / IJAZAH tidak rahasia): `admin-sekolah` boleh `view`, `download`, `verify`, `create` keduanya; `kesantrian` boleh `view` keduanya, `download` hanya IJAZAH, tidak boleh `verify` maupun `create`; `bendahara` dan `admin-psb` tidak boleh `viewAny`. Mengubah IJAZAH menjadi `is_confidential` true membuat `kesantrian` langsung tidak boleh `download` dokumen IJAZAH lama. Dokumen yang sudah diganti: `verify` false untuk semua peran. Jenis pemilik: dokumen IJAZAH-sejenis non-rahasia milik `G1` (jenis owner `wali` dibuat di test) dan milik `T1` (jenis owner `staff`) → `kesantrian` tidak boleh `view` maupun `download`; `admin-sekolah` boleh. `Document::visibleOwnerTypesFor(kesantrian)` = [`student`]; untuk `admin-sekolah` = [`student`, `guardian`, `staff`].

Route unduh
- AC-30: `GET /admin/documents/{D}/download` oleh `A` → 200, isi sama dengan berkas, `Content-Disposition` attachment dengan nama `kk-{D.id}.pdf`, header `X-Content-Type-Options: nosniff` dan `Cache-Control` memuat `private` dan `no-store`.
- AC-31: Dataset penjagaan: tamu → redirect login; `kesantrian` untuk KK (rahasia) → 403, untuk IJAZAH → 200; `bendahara` → 403; `admin-sekolah` bercakupan SLTP untuk dokumen `S1` → 404; id tidak ada → 404; berkas hilang dari disk → 404; modul `dokumen` mati → 403 (juga untuk id yang tidak ada).

UI Filament
- AC-32: Akses: `admin-sekolah` membuka `/admin/documents` dan `/admin/document-types` → 200, menu "Dokumen" dan "Jenis dokumen" tampil; `kesantrian` → `/admin/documents` 200, `/admin/document-types` 403, menu "Jenis dokumen" tidak tampil; `bendahara`, `admin-psb`, `musyrif` → keduanya 403. Modul `dokumen` mati → keduanya 403 untuk `admin-sekolah` dan super-admin, menu tidak tampil.
- AC-33: `ManageDocumentTypes`: create lewat modal → tersimpan; `code_taken` tampil sebagai error di field `code` dan jumlah baris tetap; saat edit field `code` disabled; aksi `delete` tersembunyi untuk `KK` yang punya dokumen dan untuk jenis yang dirujuk syarat PSB, tampil dan menghapus untuk jenis tanpa rujukan; tidak ada bulk delete; toggle `is_required` tidak tampil saat owner `wali`. Login `admin-sekolah` bercakupan SLTP: jenis `IJAZAH` yang dokumennya hanya milik `S1` (SD) → aksi `delete` tersembunyi dan field `owner` disabled saat edit; `documents_count` IJAZAH = 1.
- AC-34: `ListDocuments` sebagai `A`: filter status bawaan `menunggu` (dokumen `valid` tidak tampil sebelum filter diubah); filter "Versi terbaru saja" bawaan aktif (dokumen yang diganti tersembunyi; saat dimatikan tampil dengan badge "Diganti"); pencarian nama santri `S2` hanya menampilkan dokumen `S2`; kolom nomor menampilkan `3201••••••••0001` dan tidak pernah nomor penuh; tidak ada aksi create, edit, delete, maupun bulk action. Sebagai `kesantrian`, baris KK menampilkan "Rahasia" di kolom nomor, dan aksi "Unduh berkas" serta "Verifikasi dokumen" tidak tampil untuk KK; "Unduh berkas" tampil untuk IJAZAH.
- AC-35: Aksi "Verifikasi dokumen" dari `ListDocuments` (status `valid`) → dokumen `valid`, `verified_by` `A`, notifikasi sukses; untuk BPJS kedaluwarsa → error `document_expired` tampil di field `status`, status tetap. Aksi tersembunyi untuk dokumen yang sudah diganti.
- AC-36: `DocumentsRelationManager` di `EditStudent` (`S1`) sebagai `A`: tampil; "Unggah dokumen" dengan file fake + jenis KK → dokumen baru milik `S1` di disk `private`; opsi jenis hanya memuat jenis aktif owner `santri` (tidak memuat `SERTIFIKAT`, jenis `wali`, maupun jenis nonaktif); error validasi (`issued_on_future`) tampil di field form; deskripsi tabel memuat "Berkas wajib belum lengkap" beserta nama jenis yang kurang, lalu "Berkas wajib lengkap." setelah KK dan AKTA valid. Aksi "Unggah versi baru" pada dokumen current membuat versi baru dan baris lama tampil "Diganti" saat filter versi terbaru dimatikan.
- AC-37: Relation manager di `EditGuardian` (`G1`) dan `EditStaff` (`T1`) sebagai `A`: tampil, opsi jenis hanya owner `wali`/`staff` masing-masing; unggah berhasil dengan `documentable_type` `guardian`/`staff`. Modul `dokumen` mati → tab dokumen tidak tampil di ketiga halaman, halaman edit pemilik tetap 200. Login `admin-sekolah` bercakupan SLTP: tab dokumen di `EditGuardian` untuk `G1` (hanya terhubung ke `S1` SD) dan untuk wali tanpa santri **tidak tampil**; untuk wali yang terhubung ke `S2` tampil dan unggah berhasil.
- AC-38: User yang punya `create:document` tetapi tidak punya `view-confidential:document` (peran uji dibuat di test) tidak melihat KK/AKTA di opsi jenis unggah. Mengirim `document_type_id` KK langsung lewat Livewire (`callTableAction`/`mountTableAction` dengan data KK) → error `document_type_id`/`confidential_not_allowed`, tidak ada baris baru dan disk `private` kosong. "Unggah versi baru" untuk dokumen KK tidak tampil bagi user itu, dan pemanggilan langsung ditolak dengan error yang sama.
- AC-39: `StudentsTable`: filter "Berkas wajib" = "Belum lengkap" menampilkan santri tanpa KK/AKTA valid dan menyembunyikan santri lengkap; "Lengkap" kebalikannya. Modul `dokumen` mati → filter tidak tersedia dan daftar santri tetap 200.
- AC-40: Penjaga hapus: `GuardiansTable` aksi `delete` tersembunyi untuk wali yang punya dokumen (meski tanpa santri); `EditGuardian` dan `EditStaff` header `delete` tersembunyi bila pemilik punya dokumen, tampil bila tidak. Login `admin-sekolah` bercakupan SLTP: wali `G1` (dokumen milik `G1`, santri hanya `S1` SD — dokumennya tidak terlihat oleh user ini) dan wali tanpa santri yang punya dokumen → `delete` tersembunyi di `GuardiansTable` dan `EditGuardian`; staff `T2` yang punya dokumen → `delete` tersembunyi di `EditStaff`.

Teks
- AC-41: Semua kunci `pondok.dokumen.errors.*` yang dipakai Action ada di `lang/id/pondok.php` (iterasi daftar alasan, `__()` tidak mengembalikan kunci mentah); `pondok.document.statuses.{menunggu,valid,ditolak,diganti}` = "Menunggu", "Valid", "Ditolak", "Diganti"; `pondok.navigation.dokumen` = "Dokumen".

## Rencana test

| AC | File | Nama test |
|---|---|---|
| 1, 2, 3 | `tests/Feature/Database/DokumenConstraintsTest.php` | `AC-1: creates the documents table from the ERD`, `AC-2: restricts deleting referenced rows`, `AC-3: encrypts document numbers at rest` |
| 4, 5, 28 | `tests/Feature/Models/DocumentTest.php` | `AC-4: maps documentable morph aliases`, `AC-5: masks numbers and derives display status`, `AC-28: scopes documents by unit` |
| 6, 7 | `tests/Feature/Database/Seeders/DokumenSeedersTest.php`, `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (dataset + test baru) | `AC-6: enables dokumen on a fresh install`, `AC-7: creates document permissions`, `AC-7: keeps document update and delete from every role but super-admin` |
| 8–10 | `tests/Feature/Actions/Dokumen/SaveDocumentTypeTest.php` | `AC-8: creates a document type`, `AC-9: rejects invalid document types` (dataset), `AC-10: locks code and used owners on edit` |
| 11–17 | `tests/Feature/Actions/Dokumen/UploadDocumentTest.php` | `AC-11: stores a document on the private disk`, `AC-12: keeps several documents of one type`, `AC-13: rejects invalid uploads` (dataset), `AC-14: accepts optional expiry dates`, `AC-15: checks the document owner` (dataset), `AC-16: removes the stored file when saving fails`, `AC-17: logs uploads without personal data` |
| 18–21 | `tests/Feature/Actions/Dokumen/ReplaceDocumentTest.php` | `AC-18: adds a new version without overwriting`, `AC-19: replaces only the current version`, `AC-20: validates replacements`, `AC-21: logs replacements without numbers` |
| 22, 23 | `tests/Feature/Actions/Dokumen/VerifyDocumentTest.php` | `AC-22: verifies and corrects documents`, `AC-23: rejects invalid verifications` (dataset) |
| 24–27 | `tests/Feature/Actions/Dokumen/CheckDocumentCompletenessTest.php` | `AC-24: lists missing required documents`, `AC-25: explains why a type is incomplete` (dataset), `AC-26: ignores optional, inactive and foreign documents`, `AC-27: matches the student completeness scopes` |
| 29 | `tests/Feature/Policies/DocumentPolicyTest.php` | `AC-29: guards confidential documents by permission` (dataset), `AC-29: limits owner types per role` |
| 30, 31 | `tests/Feature/Http/Dokumen/DocumentDownloadTest.php` | `AC-30: downloads a document as an attachment`, `AC-31: guards document downloads` (dataset) |
| 32 | `tests/Feature/Filament/DokumenAccessTest.php` | `AC-32: shows dokumen pages per role` (dataset), `AC-32: hides dokumen when the module is off` |
| 33 | `tests/Feature/Filament/Resources/DocumentTypes/ManageDocumentTypesTest.php` | `AC-33: manages document types`, `AC-33: locks types used outside the unit scope` |
| 34, 35 | `tests/Feature/Filament/Resources/Documents/ListDocumentsTest.php` | `AC-34: lists the verification queue`, `AC-34: hides confidential data from kesantrian`, `AC-35: verifies a document from the list` |
| 36–38 | `tests/Feature/Filament/Resources/Documents/DocumentsRelationManagerTest.php` | `AC-36: uploads and replaces student documents`, `AC-37: manages guardian and staff documents`, `AC-38: hides confidential types without permission`, `AC-38: rejects a confidential type sent directly`, `AC-37: hides guardian documents outside the unit scope` |
| 39, 40 | `tests/Feature/Filament/Resources/Students/StudentDocumentFilterTest.php`, `tests/Feature/Filament/Resources/Documents/OwnerDeleteGuardTest.php` | `AC-39: filters students by required documents`, `AC-40: keeps owners with documents from deletion`, `AC-40: guards deletion for unit-scoped admins` |
| 41 | `tests/Feature/Filament/Resources/Documents/DokumenTranslationsTest.php` | `AC-41: translates every dokumen message` |

Catatan untuk red-tester: test yang memerlukan `document_types`/`DocumentTypeSeeder` bergantung pada implementasi F009; bila F009 belum di repo, berhenti dan laporkan (jangan membuat migration `document_types` sendiri).

## Urutan implementasi

1. Migration `documents` + model `Document` (cast, konstanta, scope, accessor, masking) + factory (state `valid`, `rejected`, `forStudent`, `forGuardian`, `forStaff`, `replacedBy`); morph map di `AppServiceProvider`; relasi `documents()` di `Student`/`Guardian`/`Staff`/`DocumentType` — AC-1–5.
2. `HasUnitScope` + `applyUnitScope` pada `Document`; helper tanpa scope `hasAnyDocuments()`/`DocumentType::isInUse()`; `Document::visibleOwnerTypesFor()` — AC-28.
3. `SettingSeeder::ENABLED_BY_DEFAULT` + `RolePermissionSeeder` + `shield:generate` + method policy `verify`/`download`/`viewConfidential` — AC-6, 7, 29.
4. `SaveDocumentType` + kunci `pondok.dokumen.errors.*` miliknya — AC-8–10.
5. `UploadDocument` (validasi bersama, penyimpanan, pembersihan, activity) + kunci error miliknya — AC-11–17.
6. `ReplaceDocument` + kunci error miliknya — AC-18–21.
7. `VerifyDocument` + kunci error miliknya — AC-22, 23.
8. `CheckDocumentCompleteness` + scope `Student::withIncompleteDocuments`/`withCompleteDocuments` — AC-24–27.
9. Route + controller unduh — AC-30, 31.
10. `DocumentTypeResource` + navigasi + teks — AC-32, 33.
11. `DocumentResource` (daftar, filter, aksi bersama) — AC-34, 35.
12. `DocumentsRelationManager` di tiga resource pemilik — AC-36–38.
13. Filter "Berkas wajib" di `StudentsTable` + penjaga hapus wali/staff — AC-39, 40.
14. Sisa teks UI `lang/id`, design guide §4, checklist §0, Pint — AC-41.
15. Sinkronkan `docs/erd.md` bagian Dokumen (oleh green-coder, commit `docs(dokumen)`): `documentable_type` berisi alias morph (`student`, `guardian`, `staff`, `achievement`), `number` bertipe `text` (terenkripsi), unique `replaced_by_id`, FK `source_applicant_document_id` ditunda sampai F010/F012, aturan jenis pemilik per peran.

## Risiko & pertanyaan terbuka

1. **Dependensi F009.** `feature_list.json` hanya mencantumkan F002, tetapi F013 membutuhkan tabel `document_types` dari F009. *Default:* orkestrator menambah `F009` ke `depends_on` F013 atau minimal memastikan migration/model/seeder `document_types` F009 sudah ada sebelum RED. F013 tidak membuat ulang tabel itu.
2. **Peran.** *Default:* `admin-sekolah` mengelola jenis dokumen dan dokumen, memverifikasi, dan membuka dokumen rahasia; `kesantrian` lihat-saja tanpa rahasia (kartu BPJS, dsb.); `admin-psb` tidak mendapat apa pun di F013 (penyalinan berkas PSB di F012 berjalan lewat Action tanpa permission dokumen); peran lain tidak. Catatan: `kesantrian` tidak bisa membuka halaman edit santri, sehingga ia melihat dokumen dari menu Dokumen, bukan dari tab santri.
3. **Satu atau banyak dokumen per jenis.** *Default:* banyak dokumen current per jenis diperbolehkan (rapor per semester, banyak foto/sertifikat prestasi di F015); penggantian eksplisit lewat "Unggah versi baru". Kelengkapan cukup satu yang valid.
4. **Alasan penolakan.** ERD `documents` tidak punya kolom catatan (berbeda dengan `applicant_documents.note`). *Default:* tidak ditambahkan; bila dibutuhkan untuk portal wali, usulkan entri baru (lihat laporan).
5. **Status di design guide.** Design guide punya "perlu revisi", ERD punya `menunggu`/`ditolak`. *Default:* ikuti ERD; design guide §4 diperbarui (menunggu `warning`, ditolak `danger`, diganti `gray` turunan).
6. **Audit unduhan dokumen rahasia.** Berguna untuk UU PDP tetapi di luar lingkup tertulis. *Default:* tidak di F013; usulan entri baru.
7. **Edit/hapus dokumen.** *Default:* tidak ada (koreksi lewat versi baru atau `ditolak`); permission `update`/`delete` hanya dimiliki super-admin dan tidak dipakai UI. Penghapusan data pribadi atas permintaan (hak subjek data UU PDP) belum ditangani.
8. **Ukuran berkas 5 MB.** cPanel sering membatasi `upload_max_filesize` 2–8 MB dan Livewire memakai unggahan sementara. *Default:* `Document::MAX_SIZE_KB = 5120`; bila server lebih kecil, unggahan gagal di level PHP. Bukan setting yang bisa diubah dari panel di F013.
9. **Kerahasiaan mengikuti jenis saat ini, bukan snapshot.** *Default:* ya; menandai jenis sebagai rahasia langsung melindungi dokumen lama, membuka kembali juga langsung berlaku.
10. **Cakupan dokumen staff dan wali.** *Default:* staff tidak berjenjang (terlihat semua pemegang permission); wali terlihat bila terhubung ke santri dalam cakupan; tipe tak dikenal fail-closed bagi user bercakupan.
11. **`uploaded_by` wajib.** F012 (salin berkas PSB) harus mengisi dengan user yang menjalankan daftar ulang; proses tanpa user (console) tidak bisa membuat dokumen. Dicatat untuk F012.
12. **`source_applicant_document_id` tanpa FK.** F010/F012 menambah FK; SQLite akan membangun ulang tabel `documents` (self-FK `replaced_by_id`, unique). F010/F012 wajib menguji index dan FK `documents` tetap ada (pola catatan F009). Perlu catatan di entri F010/F012.
13. **Pencarian nomor dokumen** tidak mungkin karena terenkripsi (non-deterministik). *Default:* diterima; pencarian lewat nama/NIS pemilik.
14. **Berkas lama tidak pernah dihapus** dari disk (riwayat versi). Pertumbuhan disk di shared hosting perlu dipantau; di luar lingkup.
15. **Berkas sementara Livewire.** Unggahan `FileUpload` lewat `livewire-tmp` di disk default (`local` → `storage/app/private`, tidak bisa diakses web) berisi KK/akta tak terenkripsi sampai dibersihkan Livewire. *Syarat:* disk sementara Livewire dan `FILESYSTEM_DISK` tidak boleh `public`; green-coder memastikan `config/livewire.php` (`temporary_file_upload.disk`) null/`local` dan mencatatnya di laporan. Dicatat juga untuk panduan instalasi.
16. **Jenis pemilik per peran** memakai `update:guardian`/`update:staff` sebagai penanda "pengelola data wali/staff", bukan permission dokumen baru. Bila nanti ada peran yang boleh melihat dokumen staff tanpa mengubah data staff, perlu permission khusus (entri baru).

## Riwayat revisi

- r1 (DRAFT): rencana awal.
- r2 (DRAFT, menjawab Review #1): #1 major — semua pemeriksaan integritas (`owner_locked`, hapus jenis dokumen, `documents_count`, hapus wali/staff) memakai query tanpa unit scope; bagian baru "Pemeriksaan integritas tanpa cakupan"; AC-10, AC-33, AC-40 ditambah kasus `admin-sekolah` bercakupan SLTP dengan dokumen santri SD. #2 — tab dokumen wali disembunyikan bila wali tidak terhubung ke santri dalam cakupan; AC-37 diperluas. #3 — aturan jenis pemilik per peran (`kesantrian` hanya dokumen santri; wali butuh `update:guardian`, staff `update:staff`); AC-29 diperluas. #4 — pemeriksaan server-side jenis rahasia di aksi unggah/versi baru (`confidential_not_allowed`); AC-38 diperluas. #5 — langkah 15 sinkronisasi `docs/erd.md` oleh green-coder. #6 — kunci error ditulis bersama tiap Action (langkah 4–7). #7 — `today()`/`now()` tanpa zona hardcode. #8 — header `Cache-Control: private, no-store` (AC-30) dan Risiko #15 disk sementara Livewire. #9 — sudah ditangani orkestrator di notes F015.

## Review #1 — 2026-10-15

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | Penjaga hapus wali/staff, `DocumentType` `documents_exists`/`documents_count`, `SaveDocumentType` `owner_locked`, AC-40, AC-33 | `Document` memakai global scope jenjang, sedangkan relasi `documents()` (MorphMany/HasMany) dan `withExists('documents')` otomatis ikut terscope bagi user bercakupan `unit_user` (CLAUDE.md "Cakupan jenjang ... global scope"). Wali tanpa santri (atau wali santri jenjang lain) tidak terlihat oleh admin bercakupan, sehingga `DeleteAction` wali tampil, wali terhapus, dan dokumennya yatim (tanpa FK polymorphic). Jenis dokumen yang dipakai dokumen jenjang lain tampil bisa dihapus (berakhir QueryException/500 dari `restrictOnDelete`), dan `owner_locked` bisa lolos salah. AC-40 hanya menguji user tanpa cakupan. | Semua pemeriksaan integritas ("punya dokumen") wajib memakai query tanpa unit scope (`Document::withoutUnitScope()` atau `withoutGlobalScopes()` di `withExists`/`exists()`/`withCount`). Tambahkan ke AC-33/AC-40: user `admin-sekolah` bercakupan SLTP, wali dan jenis dokumen yang dokumennya hanya milik santri SD, aksi `delete` tersembunyi dan `owner` terkunci. |
| 2 | minor | Cakupan wali vs `GuardianResource` | `Guardian` tidak punya global scope, sehingga admin bercakupan melihat semua wali, tetapi dokumen wali tanpa santri dalam cakupan tidak terlihat dan unggah ditolak `owner_not_found`. Tab dokumen tampil tetapi tidak berguna dan pesannya membingungkan (wali baru yang belum dihubungkan ke santri tidak bisa diberi dokumen). | `canViewForRecord` tab dokumen wali sebaiknya false bila wali di luar cakupan, atau catat perilaku ini eksplisit di AC-37 (user bercakupan pada wali di luar cakupan). |
| 3 | minor | Pembagian peran `kesantrian` | `kesantrian` tidak punya permission `Staff`, tetapi `view-any:document` membuatnya melihat baris dokumen staff dan wali (nomor termasker, jenis non-rahasia). Untuk data pribadi staff (UU PDP) ini lebih luas dari kebutuhan asrama (BPJS santri). | Batasi cakupan `kesantrian` ke dokumen santri (policy `view` per owner), atau minta konfirmasi bahwa jenis owner `staff`/`wali` yang dibuat admin otomatis dianggap rahasia. Tambahkan satu kasus ke AC-29. |
| 4 | minor | Otorisasi `UploadDocument` server-side | AC-38 hanya menguji opsi Select tersembunyi. Permintaan Livewire dengan `document_type_id` jenis rahasia oleh user tanpa `view-confidential:document` tidak diuji. | Tambah assert di AC-36/38: mengirim id jenis rahasia langsung ditolak (validasi `in` Select atau cek policy di handler aksi). |
| 5 | minor | Konsistensi dengan ERD | ERD menulis `documentable_type` berisi nama kelas (`Student, Guardian, ...`), `number` bertipe string, dan tidak memuat unique `replaced_by_id`. Rencana memakai alias morph, `text`, dan unique. Keputusan beralasan, tetapi ERD (prioritas di atas CLAUDE.md) belum disinkronkan. | Tambahkan langkah di urutan implementasi: perbarui `docs/erd.md` (alias morph, `number` text, unique `replaced_by_id`, FK `source_applicant_document_id` ditunda). |
| 6 | minor | Teks `lang/id` di langkah 14 | Langkah 4-9 melempar pesan `pondok.dokumen.errors.*` yang baru ditulis di langkah 14, sehingga micro-commit awal menghasilkan kunci mentah. | Tulis kunci error bersama masing-masing Action pada langkah Action tersebut; langkah 14 hanya untuk UI. |
| 7 | minor | Zona waktu | "today('Asia/Jakarta')" di-hardcode, padahal `config/app.php` sudah `Asia/Jakarta`. | Pakai `today()`/`now()` bawaan (zona aplikasi); test membekukan waktu saja. |
| 8 | minor | Unduh dan berkas sementara | Respons unduh dokumen pribadi belum menyebut `Cache-Control: private, no-store`. Berkas unggahan sementara Livewire (`livewire-tmp`) di disk default `local` (`storage/app/private`, aman dari web) tetap berisi KK/akta tak terenkripsi sampai dibersihkan. | Tambah header `Cache-Control: private, no-store`; catat di Risiko bahwa disk default harus tetap non-publik dan `FILESYSTEM_DISK` tidak boleh `public`. |
| 9 | minor | `feature_list.json` F015 | F015 akan memperluas `DOCUMENTABLE_TYPES` dan `applyUnitScope` (cabang `achievement`, unggah sertifikat), tetapi notes F015 kosong sehingga titik perluasan bisa terlewat. | Tambahkan catatan di entri F015 (bersamaan dengan catatan F010/F012 yang sudah ada). |

Kesesuaian lain yang sudah diperiksa dan baik: seluruh acceptance F013 dan PRD 3.6 (kecuali prestasi, ditunda eksplisit ke F015 dengan titik perluasan) tercakup AC; `number` bertipe `text` dengan cast `encrypted` (ciphertext > 255) dan tidak masuk activitylog; disk `private` dengan nama acak dan unduh lewat route berizin (modul, binding terscope, policy, `nosniff`); riwayat versi lewat baris baru, `replaced_by_id` unik dan `restrictOnDelete`; `morphMap` non-enforce sehingga aman bagi Spatie; query `whereExists` portabel; tanpa dependensi Redis/Node. `depends_on` F002 dan F009 benar, tetapi RED menunggu implementasi F009 (migration `document_types`).

## Review #2 — 2026-10-15

Verdict: APPROVED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Verifikasi perbaikan Review #1 | Major #1 dan minor #2–#8 sudah ditangani dan terpetakan ke AC: pemeriksaan integritas tanpa unit scope (bagian baru, AC-10/33/40 dengan kasus SLTP vs SD), tab wali di luar cakupan (AC-37), `visibleOwnerTypesFor` (AC-29), `confidential_not_allowed` (AC-38), sinkron ERD (langkah 15), kunci lang per Action, `today()`/`now()`, `Cache-Control` (AC-30) dan Risiko #15. Tidak ada masalah baru yang bersifat blocker atau major. | - |
| 2 | minor | `visibleOwnerTypesFor` | Aturan owner `guardian` butuh `update:guardian` dan `staff` butuh `update:staff`: pastikan `admin-sekolah` memang punya keduanya (saat ini `resourcePermissions('Guardian')` dan `('Staff')` penuh, jadi benar) dan tambahkan assert di AC-7/AC-29 agar perubahan seeder di masa depan tidak diam-diam menyembunyikan dokumen wali/staff. | Satu assert di AC-29: `visibleOwnerTypesFor(admin-sekolah)` berisi tiga alias (sudah tertulis) sudah cukup; pertahankan. |
| 3 | minor | Pemeriksaan server-side jenis rahasia | `confidential_not_allowed` hanya dicek di handler aksi UI, bukan di Action `UploadDocument`/`ReplaceDocument` (konsisten dengan pola "Action tanpa permission"). Pemanggil lain (F012) memakai Action langsung, jadi aman, tetapi jangan menambah pemanggil UI baru tanpa pemeriksaan yang sama. | Catatan untuk green-coder: satu helper (`Gate::allows('uploadType', ...)`) dipakai semua aksi unggah UI. |

Plan siap untuk red-tester (RED), dengan syarat F009 sudah done (migration `document_types` ada di repo).

- 2026-10-16 — Keputusan orkestrator saat VERIFY: batas berkas dokumen 2 MB (Document::MAX_SIZE_KB = 2048), selaras dengan aturan unggah sementara Livewire global dari F010 dan batas upload_max_filesize umum di cPanel.
