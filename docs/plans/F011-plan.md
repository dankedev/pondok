# F011 psb-seleksi-penerimaan — Seleksi & penerimaan pendaftar

Status rencana: DRAFT

Owner: diklaim orkestrator di `feature_list.json` (product-manager tidak mengubah file itu pada siklus ini). Dependensi: F010 (rencana APPROVED, belum diimplementasikan) dan F005 (`done`). F010 bergantung pada F009 (sedang diimplementasikan). F018 (dashboard) ditambahkan orkestrator ke `depends_on` F011 dan diimplementasikan lebih dulu. **RED F011 baru boleh dimulai setelah F010 dan F018 `done`.**

Kontrak yang dipakai:
- F009: `AdmissionWave`, `AdmissionTrack` (`scholarship_id`, `scholarship()`), `SaveAdmissionTrack`, `AdmissionTrackResource` (halaman `ManageAdmissionTracks`), grup navigasi `pondok.navigation.psb`, helper test `psbUser()`, `psbFixture()`.
- F010: `Applicant` (status, `reviewed_by`, `reviewed_at`, `review_note`, konstanta status, `statusLabel()`/`statusColor()`, `registrationInvoices()`, `registrationFeeSettled()`, `requirements()`, `documents()`, `invoices()`, `feeOverrides()`, `student()`, activitylog `psb` `logOnly(['status'])`), `ApplicantDocument`, `GenerateRegistrationInvoices` (pola tagihan pendaftar, retry nomor di luar transaksi), `ApplicantResource` + `ViewApplicant`, `ApplicationStatus` (Livewire cek status), `ApplicantPolicy`, permission `generate-invoices:applicant`, pemisahan `billing_group` PSB di `SaveFeeType`, `SaveFeeOverride` memvalidasi `applicant_id`, helper test `pendaftaranFixture()`, `pendaftaranApplicant()`, `pendaftaranPayRegistration()`.
- F005–F007: `ResolveFeeRate`, `ResolveFeeOverride::forApplicant`, `ApplyFeeOverride`, `SaveFeeOverride`, `EndFeeOverride`, `GenerateInvoiceNumber`, `CancelInvoice`, `RecordPayment`, `Scholarship`.
- F018: trait `InteractsWithDashboardModule`, `PsbOverviewWidget` (kerangka, `hasMetricSource() = false`), view `module-unavailable`, kunci `pondok.dashboard.psb.heading`.

## Ringkasan & tujuan

Panitia PSB memutuskan nasib setiap pendaftar dari panel: menandai berkas sudah diverifikasi, menerima, mencadangkan, menolak, mengembalikan ke tahap pengajuan, dan membatalkan penerimaan. Saat pendaftar **diterima**, sistem otomatis:

1. menerapkan beasiswa jalur (`admission_tracks.scholarship_id`) sebagai `fee_overrides` milik pendaftar (`applicant_id`) untuk jenis biaya yang dipetakan ke jalur itu;
2. membuat tagihan `saat_diterima` (uang pangkal, seragam, kitab) dengan tarif per gelombang dan jenjang, digabung per `billing_group`, yang bisa dicicil lewat `RecordPayment` yang sudah ada.

Keduanya idempotent: menjalankan ulang tidak membuat keringanan atau tagihan ganda. Pendaftar melihat tagihan penerimaannya di halaman cek status. Widget dashboard PSB (total, per status, 7 hari terakhir) diisi dengan data nyata.

Acuan: PRD 3.4 butir 3–4, PRD 3.10 (widget PSB), PRD 5 butir 1–2; ERD "Penerimaan Santri Baru (PSB)", "Biaya PSB" (tahap Diterima, jalur pendaftaran, keringanan khusus), "Keuangan & Tagihan" (unique `applicant_id`, `billing_group`, `active_marker`), "Konvensi umum"; CLAUDE.md "Aturan bisnis utama → PSB", "Otorisasi"; design guide §3, §4 (badge PSB), §6 (Dashboard), §7, §8, §9.

## Ruang lingkup

- Termasuk:
  - Aturan transisi status pendaftar (tabel di Model data) dengan `review_note`, `reviewed_by`, `reviewed_at`, dan tepat satu activity `psb` per keputusan.
  - Action `app/Actions/Psb/`: `MarkApplicantVerified`, `ReturnApplicantToSubmitted`, `ReserveApplicant`, `AcceptApplicant`, `RejectApplicant` (termasuk pembatalan penerimaan), `ReopenRejectedApplicant` (pemulihan khusus super-admin), `ApplyTrackScholarship`, `GenerateAcceptanceInvoices`, `SummarizeApplicants`; kelas bantu internal `RecordApplicantDecision`.
  - Pemetaan jenis biaya per jalur: tabel pivot baru `admission_track_fee_type`, perluasan `SaveAdmissionTrack` dan form `AdmissionTrackResource` (menjawab catatan F009).
  - Aksi keputusan di `ViewApplicant`, seksi "Keputusan" dan tagihan penerimaan di infolist, aksi "Buat tagihan penerimaan".
  - Halaman cek status publik (`ApplicationStatus`, F010) menampilkan semua tagihan aktif pendaftar (formulir dan penerimaan).
  - Permission `decide:applicant` dan pembagiannya ke `admin-psb`.
  - Widget `PsbOverviewWidget` diisi lewat `SummarizeApplicants` dan `hasMetricSource()` menjadi `true` (catatan F018).
  - Sinkronisasi `docs/erd.md` dan design guide §4 (lihat Urutan implementasi).
- Tidak termasuk (eksplisit):
  - Verifikasi per berkas (sudah di F010, `VerifyApplicantDocument`).
  - Daftar ulang, pembuatan `students`, pemindahan tagihan/keringanan/berkas (F012). Catatan ERD "Saat status menjadi diterima, sebuah Action membuat baris students" bertentangan dengan PRD 3.4 dan CLAUDE.md (daftar ulang); PRD menang, ERD disinkronkan.
  - Notifikasi WhatsApp/email keputusan (F021). Wali melihat keputusan di halaman cek status.
  - Kuota penerimaan per gelombang atau per jenjang (Risiko #4). Kuota pendaftaran gelombang tetap aturan F010.
  - Keputusan massal (bulk accept/reject) dan jadwal/nilai tes seleksi (usulan entri baru di Risiko).
  - Memulihkan tagihan yang sudah `batal` atau keringanan yang sudah diakhiri saat pendaftar `ditolak` dipulihkan (tagihan baru dibuat lewat aksi yang ada).
  - Penerapan `applies_to` (mukim/nonmukim) pada biaya `saat_diterima` (Risiko #8).
  - Pembuatan tagihan manual, pengubahan nominal tagihan, atau pembatalan pembayaran oleh panitia (tetap bendahara, F006/F007).
  - Menampilkan `review_note` kepada pendaftar di halaman publik.

## Model data

### Tabel baru `admission_track_fee_type` (pivot, model tanpa kelas khusus)

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| admission_track_id | foreignId → admission_tracks, `restrictOnDelete` | jalur tidak pernah dihapus (F009) |
| fee_type_id | foreignId → fee_types, `restrictOnDelete` | FK ke data keuangan |
| timestamps | | |

Unique (`admission_track_id`, `fee_type_id`). Tanpa soft delete. **Tambahan di luar ERD** (menjawab catatan F009 "scholarships tidak punya fee_type"); disinkronkan ke ERD. Migration baru biasa (tabel baru, tidak merombak tabel lama), kompatibel SQLite/MySQL/Postgres.

Relasi: `AdmissionTrack::feeTypes(): BelongsToMany` (`withTimestamps()`), `FeeType::admissionTracks(): BelongsToMany`.

Arti pemetaan:
- Jenis biaya yang boleh dipetakan: frekuensi `saat_diterima`, `bulanan`, atau `tahunan` (biaya rutin setelah menjadi santri, mis. "SPP potong 50%" di ERD; keringanannya ikut pindah ke santri di F012). `saat_daftar` dan `manual` tidak boleh.
- **Pemetaan kosong = semua jenis biaya `saat_diterima` yang aktif** pada saat penerimaan (bawaan paling umum: "uang pangkal, seragam, kitab dapat potongan jalur").
- Satu jalur = satu program beasiswa = satu mode dan nilai untuk semua jenis biaya yang dipetakan (keterbatasan sadar, Risiko #2).

`AdmissionTrack::scholarshipFeeTypes(): Collection<FeeType>` = pemetaan yang jenis biayanya aktif dan frekuensinya diizinkan; bila pemetaan kosong, semua `FeeType` aktif `saat_diterima`. Urut `priority`, `id`.

### `applicants` (kolom dari F010, tanpa kolom baru)

- `status`: aturan transisi baru di model, konstanta `Applicant::TRANSITIONS`:

| Dari | Ke | Action | Catatan wajib | Syarat |
|---|---|---|---|---|
| `diajukan` | `diverifikasi` | `MarkApplicantVerified` | tidak | formulir lunas (`registrationFeeSettled()`) dan semua syarat wajib (`requirements()` dengan `is_required`) punya berkas `valid` |
| `diverifikasi` | `diajukan` | `ReturnApplicantToSubmitted` | ya | membuka kembali unggah berkas (F010 `acceptsUploads()`) |
| `diverifikasi` | `cadangan` | `ReserveApplicant` | ya | |
| `diverifikasi`, `cadangan` | `diterima` | `AcceptApplicant` | tidak | |
| `diajukan`, `diverifikasi`, `cadangan` | `ditolak` | `RejectApplicant` | ya | |
| `diterima` | `ditolak` | `RejectApplicant` (pembatalan penerimaan) | ya | belum menjadi santri; tagihan penerimaan belum ada pembayaran |
| `ditolak` | `diajukan` | `ReopenRejectedApplicant` (pemulihan) | ya | permission `reopen:applicant` (hanya super-admin); tagihan `batal` dan keringanan yang diakhiri tidak dipulihkan |

  `draft` tidak punya transisi keluar; `ditolak` hanya bisa dipulihkan ke `diajukan`. Transisi lain (termasuk ke status yang sama, mis. `diterima` → `diterima`) → `status`/`invalid_transition`.
- `reviewed_by`, `reviewed_at`: ditimpa setiap keputusan (keputusan terakhir); riwayat lengkap ada di activity log.
- `review_note`: catatan internal keputusan terakhir. Trim; kosong → NULL; maksimal 1000 karakter. Keputusan tanpa catatan mengosongkan catatan lama.
- Helper model baru:
  - `canTransitionTo(string $status): bool` (dipakai visibilitas aksi panel).
  - `registrationFeeRequired(): bool` = modul keuangan aktif **dan** ada jenis biaya aktif `saat_daftar` yang `ResolveFeeRate::handle($type, $wave->academicYear, $unit_id, null, null, $wave->id)` tidak NULL untuk pendaftar ini.
  - `registrationFeeCleared(): bool` (syarat verifikasi, lebih ketat dari `registrationFeeSettled()` F010): bila `registrationFeeRequired()` false → true; selain itu harus ada minimal satu tagihan pendaftaran **aktif** (bukan `batal`) dan semua tagihan pendaftaran aktif berstatus `lunas`. Tagihan `batal` tidak pernah dihitung lunas. `registrationFeeSettled()` dan `acceptsUploads()` F010 tidak diubah (unggah berkas setelah pemulihan tetap terbuka; risiko diterima).
  - `acceptanceInvoices(): HasMany` = invoices aktif (bukan `batal`) yang punya item dengan `feeType.frequency = saat_diterima` (pola `registrationInvoices()` F010). Dipakai F012 untuk `psb.min_paid_percent`.
  - `missingRequiredDocuments(): Collection<AdmissionRequirement>` = syarat wajib `forUnit` yang belum punya berkas `valid` (bila F010 sudah punya helper yang sama untuk seksi "Kelengkapan berkas", pakai ulang, jangan duplikasi).
- Activity log model tetap `logOnly(['status'])` (F010). Keputusan memakai log manual (lihat `RecordApplicantDecision`) agar tepat satu activity per keputusan.

### Data keuangan yang dibuat

- `fee_overrides` (F005): `applicant_id`, `student_id` NULL, `fee_type_id`, `scholarship_id` = program jalur, `mode` dan `value` = `mode`/`default_value` program saat penerimaan (snapshot; mengubah program kemudian tidak mengubah keringanan), `reason` = `__('pondok.applicant.track_scholarship_reason', ['track' => $track->name])` (≤255), `valid_from` = tanggal penerimaan (Asia/Jakarta), `valid_until` NULL, `approved_by` = user yang menerima.
- `invoices` + `invoice_items` (F006): sama dengan tagihan pendaftaran F010, tetapi dari jenis biaya `saat_diterima` (detail di `GenerateAcceptanceInvoices`). Unique DB (`applicant_id`, `billing_group`, `active_marker`) menjamin satu tagihan aktif per grup; F010 menjamin grup `saat_diterima` terpisah dari grup `saat_daftar`.
- Tidak ada yang dihapus. Pembatalan memakai `CancelInvoice` (status `batal`) dan `EndFeeOverride` (`valid_until`).

## Action class

Semua Action melempar `ValidationException::withMessages([$field => __("pondok.psb.errors.{$reason}")])` (pola F009/F010). "Hari ini" = `today()` (zona aplikasi `Asia/Jakarta`). Pendaftar selalu dibaca ulang di dalam transaksi dengan `Applicant::withoutGlobalScopes()->lockForUpdate()->find($id)`; pendaftar terhapus (soft delete) → `applicant_not_found`. Otorisasi (policy) dicek di panel, bukan di Action.

### `App\Actions\Psb\RecordApplicantDecision` (kelas bantu internal, bukan dipanggil UI)

`handle(Applicant $locked, User $by, string $to, ?string $note, bool $noteRequired): Applicant`
1. `$locked->canTransitionTo($to)` → selain itu `status`/`invalid_transition`.
2. Catatan: trim; `$noteRequired` dan kosong → `review_note`/`review_note_required`; lebih dari 1000 karakter → `review_note`/`review_note_invalid`.
3. Di dalam `activity()->withoutLogs(...)`: isi `status`, `reviewed_by = $by->id`, `reviewed_at = now()`, `review_note` (NULL bila kosong), simpan.
4. Satu activity manual: log `psb`, subject pendaftar, causer `$by`, event `updated`, properti `old.status`, `attributes.status`, `note` (catatan atau NULL), deskripsi `__('pondok.applicant.activity_decided')`. Tanpa data pribadi lain.

Validasi dilakukan sebelum perubahan apa pun; gagal → tidak ada perubahan dan tidak ada activity.

### `App\Actions\Psb\MarkApplicantVerified`

`handle(Applicant $applicant, User $by, ?string $note = null): Applicant` — `diajukan` → `diverifikasi`.
Dalam satu `DB::transaction`: kunci pendaftar; transisi sah (`invalid_transition`); `registrationFeeCleared()` → `registration_fee_unpaid` (kunci F010); `missingRequiredDocuments()` kosong → `documents_incomplete` (pesan menyebut jumlah berkas wajib yang belum valid lewat `:count`); lalu `RecordApplicantDecision` (catatan opsional).

### `App\Actions\Psb\ReturnApplicantToSubmitted`

`handle(Applicant $applicant, User $by, string $note): Applicant` — `diverifikasi` → `diajukan`, catatan wajib. Status berkas tidak diubah; pendaftar bisa mengunggah ulang berkas yang bukan `valid`.

### `App\Actions\Psb\ReserveApplicant`

`handle(Applicant $applicant, User $by, string $note): Applicant` — `diverifikasi` → `cadangan`, catatan wajib. Tanpa efek keuangan.

### `App\Actions\Psb\AcceptApplicant`

`handle(Applicant $applicant, User $by, ?string $note = null): array{applicant: Applicant, billing: array|null, billing_failed: bool}`
1. `DB::transaction`: kunci pendaftar; `RecordApplicantDecision` ke `diterima` (catatan opsional). Commit.
2. Setelah commit, bila `Setting::moduleEnabled('keuangan')`: `GenerateAcceptanceInvoices::handle($applicant, $by)` → `billing` berisi hasilnya. Exception apa pun dari langkah ini (termasuk `ValidationException` dari `SaveFeeOverride`, `applicant_not_accepted` karena status berubah di tengah jalan, dan `RuntimeException` nomor bentrok) ditangkap, `Log::error` (registration_no, pesan), `billing_failed = true`; **penerimaan tidak dibatalkan**. Panitia mengulang lewat aksi "Buat tagihan penerimaan" (idempotent).
3. Modul keuangan mati → `billing = null`, `billing_failed = false`, tidak ada keringanan maupun tagihan.

Penerimaan kedua untuk pendaftar yang sudah `diterima` → `invalid_transition` (tidak ada keringanan/tagihan baru). Dua admin menerima bersamaan: kunci baris membuat yang kedua gagal `invalid_transition`.

### `App\Actions\Psb\ReopenRejectedApplicant`

`handle(Applicant $applicant, User $by, string $note): Applicant` — `ditolak` → `diajukan`, catatan wajib, untuk memperbaiki penolakan yang keliru.
- Dalam satu `DB::transaction`: kunci pendaftar; transisi sah (`invalid_transition`); `RecordApplicantDecision` (catatan wajib, tepat satu activity `psb` `updated` dengan `old.status` `ditolak`, `attributes.status` `diajukan`, `note`).
- Tanpa efek keuangan: tagihan `batal` tetap `batal`, keringanan yang diakhiri tetap berakhir, tidak ada tagihan atau keringanan baru. Akibatnya:
  - Bila tagihan formulir dibatalkan saat ditolak, `registrationFeeSettled()` (F010) bernilai true sehingga unggah berkas langsung terbuka (risiko diterima). Namun `MarkApplicantVerified` memakai `registrationFeeCleared()`: selama jenis biaya formulir bertarif, pendaftar tidak bisa diverifikasi (dan karenanya tidak bisa diterima) sampai panitia membuat tagihan formulir baru lewat aksi F010 "Buat tagihan pendaftaran" (tampil karena status `diajukan` dan tidak ada tagihan pendaftaran aktif) dan tagihan itu lunas.
  - Bila nanti diterima lagi, `GenerateAcceptanceInvoices` membuat tagihan penerimaan baru (grup lama hanya punya tagihan `batal`) dan `ApplyTrackScholarship` membuat keringanan jalur baru mulai tanggal penerimaan baru (yang lama sudah berakhir; bila diakhiri hari yang sama, keringanan lama masih berlaku hari itu → `already_applied`, dan tetap dipakai item tagihan bulan itu).
- Otorisasi di panel lewat policy `reopen`; Action tidak memeriksa permission.
- Kuota gelombang tidak berubah (F010 menghitung semua status selain `draft`).

### `App\Actions\Psb\ApplyTrackScholarship`

`handle(Applicant $applicant, User $by): array{created: list<int>, skipped: list<array{fee_type_id: int|null, reason: string}>}`
- Jalur tanpa `scholarship_id` → `created` dan `skipped` kosong.
- Program nonaktif → tanpa keringanan, `skipped` = [`fee_type_id` NULL, `scholarship_inactive`], `Log::warning`.
- Untuk setiap jenis biaya di `$track->scholarshipFeeTypes()`:
  - Sudah ada keringanan pendaftar untuk jenis biaya itu yang masih berlaku pada hari ini atau sesudahnya (`activeBetween(today, 9999-12-31)`): bila `scholarship_id` sama dengan program jalur → `already_applied`; selain itu → `existing_override` (keringanan khusus yang diberi panitia/bendahara menang; tidak ditimpa, tidak digabung).
  - Selain itu: `SaveFeeOverride::handle([...], $by)` dengan data di Model data. Id baru masuk `created`.
- Semua di dalam satu `DB::transaction` yang diawali `Applicant::withoutGlobalScopes()->withTrashed()->lockForUpdate()->find($applicant->id)` (mencegah ganda saat dua proses berjalan bersamaan). Status dan `deleted_at` **dibaca dari baris terkunci ini**, bukan dari instance masukan: bukan `diterima` atau terhapus → `applicant_not_accepted`, tanpa keringanan baru.

### `App\Actions\Psb\GenerateAcceptanceInvoices`

`handle(Applicant $applicant, User $by): array{scholarship: array, created_invoices: int, existing_invoices: int, skipped: list<array{fee_type_id: int, reason: string}>}`
1. Cek awal pada baris terkunci (transaksi singkat): pendaftar `diterima` dan tidak terhapus → selain itu `applicant_not_accepted`.
2. `ApplyTrackScholarship::handle()` lebih dulu (idempotent), hasilnya di `scholarship`. Urutan ini memastikan potongan jalur sudah ada sebelum nominal item dihitung.
3. Jenis biaya: aktif, `frequency = saat_diterima`, urut `priority`, `id`. `applies_to` diabaikan (Risiko #8).
4. Sama dengan algoritma `GenerateRegistrationInvoices` (F010), dengan perbedaan:
   - `period` = awal bulan hari ini; `academic_year_id` = tahun ajaran gelombang pendaftar.
   - Tarif: `ResolveFeeRate::handle($type, $wave->academicYear, $applicant->unit_id, null, null, $wave->id)`; keringanan: `ResolveFeeOverride::forApplicant($applicant->id, $type, $period)`; nominal: `ApplyFeeOverride`. Hasil `null` → `skipped` `missing_rate` + `Log::warning`.
   - `due_on` = `starts_on` tahun ajaran gelombang bila setelah hari ini, selain itu hari ini.
   - `rate_source` = `jenjang`, atau `manual` bila tanpa tarif (keringanan nominal).
   - `description` = kunci F010 `pondok.applicant.invoice_item_description` (nama jenis biaya + "Gelombang 1 2027/2028").
   - `generated_by` = `$by->id`.
   - Grup yang sudah punya tagihan aktif untuk pendaftar ini → `existing_invoices`, tidak diubah (snapshot). Total 0 → status `lunas` lewat `syncStatus()`.
   - Retry nomor tagihan: loop di luar transaksi, maksimal 3 percobaan, setiap percobaan `DB::transaction` baru yang diawali kunci pendaftar (`withoutGlobalScopes()->withTrashed()->lockForUpdate()`), **cek ulang status `diterima` dan tidak terhapus pada baris terkunci** (selain itu `applicant_not_accepted`, grup ini dan grup sisanya tidak dibuat; tagihan grup yang sudah tersimpan sebelumnya tetap dan akan dibatalkan oleh `RejectApplicant` yang menyebabkan perubahan status karena ia mengunci baris yang sama), lalu cek ulang tagihan aktif grup, lalu `GenerateInvoiceNumber::handle($period)`; `UniqueConstraintViolationException` ditangkap di luar transaksi (bila tagihan aktif grup kini ada → `existing_invoices`; selain itu ulangi); gagal 3 kali → `Log::error` + `RuntimeException` (`pondok.invoice.errors.number_conflict`).
5. Saran implementasi: logika bersama dengan `GenerateRegistrationInvoices` diekstrak ke satu kelas/trait internal (mis. `App\Actions\Psb\Concerns\CreatesApplicantInvoices`) yang menerima frekuensi, status yang diizinkan, `due_on`, dan `generated_by`; perilaku F010 dan test F010 tidak boleh berubah.

### `App\Actions\Psb\RejectApplicant`

`handle(Applicant $applicant, User $by, string $note): array{applicant: Applicant, cancelled_invoices: int, ended_overrides: int}`

Dalam satu `DB::transaction`, setelah kunci pendaftar:
1. Transisi sah ke `ditolak` (`invalid_transition`).
2. Bila status awal `diterima` (pembatalan penerimaan):
   - Sudah punya santri (`student()` ada, F012) → `applicant_converted`.
   - Ada tagihan penerimaan aktif (`acceptanceInvoices()`) dengan `paid_total > 0` → `acceptance_has_payments` (bendahara membatalkan pembayaran lebih dulu lewat `CancelPayment`).
3. `RecordApplicantDecision` (catatan wajib).
4. Setiap tagihan aktif pendaftar (formulir maupun penerimaan) dengan `paid_total = 0` dibatalkan lewat `CancelInvoice::handle($invoice, $by, __('pondok.applicant.cancel_reason_rejected'))`, dijalankan sebagai langkah sistem di dalam Action: tidak ada pemeriksaan `cancel:invoice` pada `$by` (yang biasanya `admin-psb` tanpa permission keuangan); `$by` hanya menjadi causer activity `keuangan`. Pembatalan dibatasi tagihan tanpa pembayaran. Tagihan formulir yang sudah dibayar sebagian/lunas tidak diubah (urusan bendahara).
5. Keringanan otomatis jalur (pendaftar ini, `scholarship_id` = program jalur, `valid_until` NULL atau **> hari ini**, `valid_from` ≤ hari ini) diakhiri lewat `EndFeeOverride::handle($override, today())`. Keringanan dengan `valid_until` = hari ini sudah berakhir hari ini dan dilewati (tidak dihitung di `ended_overrides`), sehingga `EndFeeOverride` tidak pernah melempar `override_already_ended` dan penolakan tidak gagal karenanya. Keringanan khusus lain tidak disentuh.

Gagal di langkah mana pun → seluruh transaksi di-rollback (status, tagihan, keringanan tetap).

### `App\Actions\Psb\SummarizeApplicants` (sumber metrik dashboard)

`handle(?CarbonInterface $today = null): array{academic_year: ?AcademicYear, total: int, by_status: array<string, int>, last_7_days: int}`
- Tahun ajaran acuan = tahun ajaran tujuan dari gelombang dengan `academic_years.starts_on` terbesar (gelombang mana pun, terbuka atau tidak). Tanpa gelombang → `academic_year` NULL dan semua angka 0.
- Query `Applicant::query()` (global scope `unit_user` user login berlaku; pendaftar terhapus tidak dihitung) yang `admission_wave_id` ∈ gelombang tahun ajaran acuan, status bukan `draft`.
- `total` = jumlah; `by_status` = hitungan per status untuk kelima status `diajukan`, `diverifikasi`, `diterima`, `cadangan`, `ditolak` (selalu ada kuncinya, 0 bila tidak ada), memakai satu query `groupBy('status')` portabel.
- `last_7_days` = dari set yang sama, `created_at` ≥ awal hari `today - 6 hari` (7 hari kalender termasuk hari ini).

### Perubahan Action yang sudah ada

- `SaveAdmissionTrack` (F009): input baru opsional `fee_type_ids` (array id; tidak dikirim = pemetaan tidak berubah; array kosong = kosongkan). Setiap id harus ada → `fee_type_ids`/`track_fee_type_not_found`; frekuensinya `saat_diterima`, `bulanan`, atau `tahunan` → `fee_type_ids`/`track_fee_type_invalid`. Disimpan dengan `sync()` di transaksi yang sama. **Satu jalur activity:** di dalam `SaveAdmissionTrack`, log otomatis model dimatikan (`activity()->withoutLogs`) dan diganti tepat satu activity manual `psb` per penyimpanan yang mengubah sesuatu, dengan format sama seperti log otomatis F009 (subject jalur, causer user, event `created`/`updated`, `properties.attributes` dan `properties.old` berisi kolom fillable yang berubah) ditambah `fee_type_ids` (array id terurut) bila pemetaan berubah (`created`: `attributes.fee_type_ids` saja). Tidak ada perubahan → tidak ada activity. Dengan begitu test F009 AC-30 tetap lulus dan perubahan `scholarship_id` + `fee_type_ids` sekaligus menghasilkan satu activity. Pemetaan baru hanya memengaruhi penerimaan berikutnya.
- `GenerateRegistrationInvoices` (F010): hanya refactor ekstraksi bila dipilih (lihat di atas), tanpa perubahan perilaku.

## UI (Filament / Blade)

Rujukan design guide: §0 checklist, §3 (ikon Heroicon Outlined, kapital di awal kalimat), §4 (badge PSB), §5 (`Rp 5.000.000`, tanggal `20 Nov 2026, 10.00`), §6 Dashboard, §7 state, §8 konfirmasi (aksi berdampak memakai modal dengan deskripsi akibat; aksi berbahaya `danger`), §9 (panel tanpa sapaan; publik "Anda"), §13 (anti-ui-slop).

### `ViewApplicant` (F010) — aksi header keputusan

Semua aksi (kecuali `reopenAction`, yang memakai `reopen:applicant`) tampil hanya bila user punya `decide:applicant` (policy `decide`) dan `$record->canTransitionTo(<tujuan>)`. Semua memanggil Action dan menampilkan error Action di form/notifikasi (`ShowsActionErrorsOnForm`). Setelah berhasil: notifikasi success dan infolist dimuat ulang.

| Aksi | Label | Warna/ikon | Form | Konfirmasi |
|---|---|---|---|---|
| `verifyAction` | "Tandai terverifikasi" | `info`, `CheckBadge` | catatan opsional | modal; deskripsi "Berkas wajib sudah valid dan biaya formulir lunas." |
| `acceptAction` | "Terima" | `success`, `CheckCircle` | catatan opsional | modal; deskripsi akibat: "Tagihan uang pangkal dan biaya penerimaan lain dibuat untuk pendaftar ini." + bila jalur punya beasiswa: "Beasiswa :scholarship diterapkan otomatis." |
| `reserveAction` | "Jadikan cadangan" | `warning`, `Clock` | catatan wajib | modal |
| `returnAction` | "Kembalikan ke diajukan" | `gray`, `ArrowUturnLeft` | catatan wajib | modal; "Pendaftar bisa mengunggah ulang berkas yang belum valid." |
| `rejectAction` | "Tolak" (dari `diajukan`/`diverifikasi`/`cadangan`) | `danger`, `XCircle` | catatan wajib | modal; "Tagihan yang belum dibayar ikut dibatalkan." |
| `reopenAction` | "Pulihkan pendaftar" (dari `ditolak`) | `gray`, `ArrowPath` | catatan wajib | modal; "Status kembali menjadi diajukan. Tagihan yang sudah dibatalkan tidak dipulihkan; buat tagihan pendaftaran baru bila perlu." Tampil hanya dengan `reopen:applicant` (bukan `decide:applicant`). |
| `cancelAcceptanceAction` | "Batalkan penerimaan" (dari `diterima`) | `danger`, `XCircle` | catatan wajib | modal; "Status menjadi ditolak. Tagihan penerimaan yang belum dibayar dibatalkan dan beasiswa jalur diakhiri. Tidak bisa dibatalkan bila sudah ada pembayaran." |

Notifikasi setelah "Terima":
- `billing = null` (keuangan mati): "Pendaftar diterima."
- Berhasil: "Pendaftar diterima. :count tagihan dibuat." ditambah "Beasiswa :scholarship diterapkan untuk :count jenis biaya." bila ada; bila ada `skipped` `missing_rate` → notifikasi `warning` "Tarif belum diatur untuk: Uang pangkal, Kitab." 
- `billing_failed`: notifikasi `warning` persistent "Pendaftar diterima, tetapi tagihan gagal dibuat. Gunakan Buat tagihan penerimaan untuk mencoba lagi."

Aksi "Buat tagihan penerimaan" (`generateAcceptanceInvoicesAction`, ikon `DocumentPlus`): tampil bila modul keuangan aktif, permission `generate-invoices:applicant` (F010, dipakai ulang), status `diterima`, dan ada jenis biaya `saat_diterima` aktif yang grupnya belum punya tagihan aktif untuk pendaftar ini. `requiresConfirmation()`. Notifikasi menyebut jumlah tagihan yang dibuat atau "Tarif biaya penerimaan belum diatur" bila semua dilewati. Aksi F010 "Buat tagihan pendaftaran" tetap tersembunyi untuk status selain `diajukan`/`diverifikasi` (aturan F010).

### `ViewApplicant` — infolist

- Seksi baru "Keputusan" (setelah "Pendaftaran"): status (badge), "Diputuskan oleh" (nama reviewer atau "-"), "Waktu keputusan" (`j M Y, H.i` atau "-"), "Catatan keputusan" (atau "-").
- Seksi "Tagihan pendaftaran" F010 diganti menjadi "Tagihan" yang memuat semua tagihan aktif pendaftar (formulir dan penerimaan) dengan kolom yang sama ditambah sisa tagihan; urut `period`, `id`. Kosong → `pondok.applicant.no_invoices`.
- Seksi "Keringanan" (hanya bila ada): jenis biaya, `valueLabel()`, program beasiswa atau "Keringanan khusus", masa berlaku.

### `ListApplicants` (F010)

Kolom baru toggleable tersembunyi "Waktu keputusan" (`reviewed_at`). Tanpa aksi keputusan di baris maupun bulk (Risiko #10).

### `AdmissionTrackResource` (F009) — form

Field baru `fee_type_ids` (`CheckboxList`, label "Jenis biaya yang mendapat beasiswa", opsi: jenis biaya aktif `saat_diterima`, `bulanan`, `tahunan` ditambah yang sedang terpasang walau nonaktif, label "Nama (frekuensi)"), helper "Kosongkan untuk menerapkan beasiswa ke semua biaya saat diterima." Hanya tampil (`visible`) bila `scholarship_id` terisi (`live()`); bila kosong, nilai dikirim apa adanya (pemetaan disimpan tetapi tidak berefek). Tabel: kolom beasiswa (F009) mendapat deskripsi tambahan daftar nama jenis biaya terpetakan atau "Semua biaya saat diterima".

### Halaman cek status publik `ApplicationStatus` (F010)

- Seksi tagihan menampilkan **semua** tagihan aktif pendaftar (formulir dan penerimaan): nomor tagihan, keterangan item (nama jenis biaya, satu baris per item), total, terbayar, sisa, badge status tagihan. Judul seksi `pondok.psb_public.invoices_title` diubah menjadi "Tagihan".
- Status `diterima`: kalimat `status_help.diterima` diperbarui: "Selamat, Anda diterima. Silakan lunasi tagihan di bawah ini. Pembayaran bisa dicicil ke panitia." Kalimat status lain dari F010 tetap.
- `review_note` tidak ditampilkan.

### Widget `PsbOverviewWidget` (F018)

- `hasMetricSource()` → `true`; `getRequiredTables()` = [`applicants`, `admission_waves`]; modul `psb` (kontrak F018).
- Heading: `pondok.dashboard.psb.heading_year` "Pendaftaran santri baru :year" (nama tahun ajaran acuan), atau heading F018 bila `academic_year` NULL.
- Stat (urut): "Total pendaftar" (deskripsi ":count dalam 7 hari terakhir"), "Diajukan" (`gray`), "Diverifikasi" (`info`), "Diterima" (`success`), "Cadangan" (`warning`), "Ditolak" (`danger`). Label status dari `pondok.applicant.statuses.*`, warna dari `Applicant::statusColor()`. Angka `tabular-nums` (bawaan stat Filament).
- Tanpa gelombang: semua stat 0 dengan deskripsi stat pertama "Belum ada gelombang pendaftaran."
- Link header "Lihat pendaftar" ke index `ApplicantResource` lewat `urlIfRouteAvailable(..., ApplicantResource::canViewAny())`.
- Tanpa polling, `$isLazy = false` (kontrak F018).

### Teks `lang/id/pondok.php` (kunci baru)

`applicant.{section_decision, reviewer, reviewed_at, review_note, no_review, section_overrides, override_custom, activity_decided, track_scholarship_reason, cancel_reason_rejected, verify, verify_description, accept, accept_description, accept_scholarship_description, reserve, return, return_description, reject, reject_description, cancel_acceptance, cancel_acceptance_description, reopen, reopen_description, reopened, decided, accepted, accepted_with_invoices, scholarship_applied, missing_rates, billing_failed, generate_acceptance_invoices, generate_acceptance_invoices_description, acceptance_invoices_generated, acceptance_invoices_none_generated, section_invoices}`; `admission_track.{fee_types, fee_types_helper, all_acceptance_fees}`; `psb.errors.{invalid_transition, review_note_required, review_note_invalid, documents_incomplete, applicant_not_found, applicant_not_accepted, applicant_converted (sudah ada dari F010, pakai ulang), acceptance_has_payments, track_fee_type_not_found, track_fee_type_invalid}`; `dashboard.psb.{heading_year, total, total_description, no_waves, list_link}`; perubahan `psb_public.invoices_title`, `psb_public.status_help.diterima`. Pesan error menyebut cara memperbaiki (§9), mis. `invalid_transition` = "Status pendaftar sudah berubah atau keputusan ini tidak berlaku untuk status saat ini. Muat ulang halaman."

### Design guide

§4 baris PSB disinkronkan: `diajukan` `gray`, `diverifikasi` `info`, `diterima` `success`, `cadangan` `warning`, `ditolak` `danger` (F010 sudah menambah sebagian; F011 memastikan istilah "berkas diverifikasi" → "diverifikasi"). §3 ikon keputusan bila belum ada (`CheckBadge`, `CheckCircle`, `XCircle`, `ArrowUturnLeft`).

## Permission & cakupan

- Permission baru: `EXTRA_RESOURCE_ACTIONS['Applicant']` ditambah `decide` dan `reopen` → `decide:applicant`, `reopen:applicant`. `ApplicantPolicy::decide()` dan `ApplicantPolicy::reopen()` (pola method tambahan F010). Test Shield "creates every permission that a generated policy checks" tetap lulus.
- Pembagian: `admin-psb` mendapat `decide:applicant` (selain permission F009/F010), **tidak** `reopen:applicant`. `super-admin` semua (termasuk `reopen:applicant`). Sekolah bisa memberikan `reopen:applicant` ke peran lain lewat Shield. Peran lain tidak berubah; `bendahara` tidak bisa memutuskan pendaftar, tetapi tetap melihat dan menerima pembayaran tagihan pendaftar (F010 scope) serta melihat keringanan pendaftar di `FeeOverrideResource`.
- Keringanan otomatis dibuat atas nama user `admin-psb` yang menerima (`approved_by`), meskipun ia tidak punya permission `FeeOverride`; Action tidak memeriksa permission keuangan (Risiko #9).
- Cakupan jenjang: aksi panel bekerja pada record yang sudah tersaring global scope `Applicant` (`unit_user`); pendaftar di luar cakupan → 404. Action membaca ulang tanpa scope hanya untuk mengunci baris yang sama. `SaveFeeOverride` memeriksa `applicant_id` dengan scope user (F010), sehingga user yang menerima selalu lolos. Widget memakai scope user login.
- Saklar modul: `modules.psb.enabled` mati → `ApplicantResource` dan route 403 (F010), widget menampilkan "Modul belum tersedia." (F018). `modules.keuangan.enabled` mati → penerimaan tetap jalan tanpa keringanan dan tagihan; aksi "Buat tagihan penerimaan" tersembunyi; setelah keuangan diaktifkan, aksi itu tampil untuk pendaftar `diterima`.

## Kriteria penerimaan (bisa dites)

Fixture `penerimaanFixture()` (di atas `pendaftaranFixture()` F010): waktu `travelTo('2026-11-20 10:00')` Asia/Jakarta; jenis biaya `UANG_PANGKAL` (`saat_diterima`, grup `PSB_TERIMA`, prioritas 20), `SERAGAM` (`saat_diterima`, `PSB_TERIMA`, 30), `KITAB` (`saat_diterima`, `PSB_KITAB`, 40), `SPP` (`bulanan`, `BULANAN`, 50); tarif SLTP berlaku `Y2027`: UANG_PANGKAL Rp5.000.000, SERAGAM Rp750.000, KITAB Rp300.000, SPP Rp400.000; program `Beasiswa Tahfidz` (`persen` 50, aktif) dipasang di jalur `TAHFIDZ` dengan pemetaan [`UANG_PANGKAL`, `SPP`]; program `Beasiswa Yatim` (`nominal` 0, aktif) di jalur `YATIM` tanpa pemetaan; user `P` = `psbUser('admin-psb')` dengan `decide:applicant`. `penerimaanApplicant(string $status = 'diverifikasi', string $track = 'REGULER', string $unit = 'SLTP')` membuat pendaftar di `W1` (formulir lunas bila perlu). Nomor tagihan November mulai `INV-202611-00001`.

Skema & pemetaan jalur
- AC-1: Tabel `admission_track_fee_type` punya kolom `admission_track_id`, `fee_type_id`, timestamps; unique pasangan → `UniqueConstraintViolationException` pada duplikat; `getForeignKeys` memuat kedua FK dengan `on_delete` `restrict`; menghapus jenis biaya atau jalur yang terpetakan → `QueryException`.
- AC-2: `SaveAdmissionTrack` dengan `fee_type_ids` [UANG_PANGKAL, SPP] → pemetaan tersimpan dan tepat satu activity `psb` `updated` dengan `old.fee_type_ids` [] dan `attributes.fee_type_ids` terurut; ganti `scholarship_id` dan `fee_type_ids` sekaligus → tepat satu activity berisi `attributes.scholarship_id` dan `attributes.fee_type_ids`; create jalur baru dengan pemetaan → tepat satu activity `created` memuat `attributes.fee_type_ids`; ganti nama saja → satu activity tanpa kunci `fee_type_ids`; simpan ulang tanpa perubahan → tidak ada activity baru; tanpa kunci `fee_type_ids` → pemetaan tidak berubah; `[]` → pemetaan kosong. Gagal tanpa perubahan (dataset): id 999 → `fee_type_ids`/`track_fee_type_not_found`; jenis `saat_daftar` atau `manual` → `track_fee_type_invalid`.
- AC-3: `scholarshipFeeTypes()`: jalur TAHFIDZ → [UANG_PANGKAL, SPP] urut prioritas; jalur YATIM (tanpa pemetaan) → [UANG_PANGKAL, SERAGAM, KITAB]; jenis terpetakan yang dinonaktifkan tidak ikut; `saat_diterima` nonaktif tidak ikut pada fallback.
- AC-4: `ManageAdmissionTracks`: edit TAHFIDZ, centang `KITAB` → tersimpan; field `fee_type_ids` tidak tampil saat `scholarship_id` kosong; opsi tidak memuat `FORMULIR` (`saat_daftar`); tabel menampilkan "Semua biaya saat diterima" untuk YATIM.

Transisi status
- AC-5: Matriks transisi (dataset semua pasangan dari 6 status × 6 Action): hanya pasangan di tabel Model data yang berhasil; sisanya → `status`/`invalid_transition` tanpa perubahan `status`, `reviewed_*`, `review_note`, dan tanpa activity baru. Termasuk `diterima` → `AcceptApplicant` lagi, `ditolak` → semua Action selain `ReopenRejectedApplicant`, dan `ReopenRejectedApplicant` dari kelima status selain `ditolak`.
- AC-6: Keputusan berhasil (contoh `ReserveApplicant` dengan catatan "  Nilai tes di bawah batas  "): `status` `cadangan`, `reviewed_by` = P, `reviewed_at` = 2026-11-20 10:00, `review_note` "Nilai tes di bawah batas"; tepat satu activity log `psb` event `updated`, causer P, `old.status` `diverifikasi`, `attributes.status` `cadangan`, `note` sama; tidak ada activity otomatis model kedua. Keputusan berikutnya tanpa catatan (`AcceptApplicant`) → `review_note` NULL, `reviewed_*` diperbarui.
- AC-7: Catatan: `ReserveApplicant`, `ReturnApplicantToSubmitted`, `RejectApplicant` dengan catatan "", "   " → `review_note`/`review_note_required`; catatan 1001 karakter di Action mana pun → `review_note_invalid`; 1000 karakter → berhasil. Tanpa perubahan saat gagal.
- AC-8: `MarkApplicantVerified`: pendaftar `diajukan` dengan formulir belum lunas → `registration_fee_unpaid`; formulir lunas, KK `valid`, AKTA `menunggu` → `documents_incomplete`; KK dan AKTA `valid`, RAPOR (opsional) belum diunggah → berhasil `diverifikasi`; modul keuangan mati dan tanpa tagihan → hanya syarat berkas yang dicek; tanpa tarif `FORMULIR` untuk SLTP dan tanpa tagihan → hanya syarat berkas yang dicek.
- AC-9: `ReturnApplicantToSubmitted` dari `diverifikasi` → `diajukan` dan `acceptsUploads()` true lagi (formulir lunas); pendaftar bisa mengunggah ulang berkas AKTA yang `ditolak` lewat `UploadApplicantDocument`.
- AC-10: Pendaftar terhapus (soft delete) → semua Action keputusan `applicant_not_found`. Instance basi: pendaftar dimuat saat `diverifikasi`, lalu baris diubah ke `ditolak` di DB, `AcceptApplicant` pada instance lama → `invalid_transition`, tanpa tagihan.

Penerimaan, beasiswa jalur, dan tagihan
- AC-11: `AcceptApplicant` pendaftar REGULER SLTP `diverifikasi` → status `diterima`; tidak ada `fee_overrides` baru; dua tagihan baru milik pendaftar (`student_id` NULL, `academic_year_id` Y2027, `period` 2026-11-01, `due_on` 2027-07-01, `generated_by` P): grup `PSB_TERIMA` total 5.750.000 dengan item UANG_PANGKAL (base 5.000.000, discount 0, amount 5.000.000, `rate_source` `jenjang`) dan SERAGAM (750.000), dan grup `PSB_KITAB` total 300.000; status `belum`; nomor `INV-202611-00001` dan `-00002`; deskripsi item memuat nama jenis biaya dan "Gelombang 1". Tagihan formulir F010 tidak berubah. Hasil `billing.created_invoices` 2, `billing_failed` false.
- AC-12 (acceptance F011): pendaftar jalur TAHFIDZ diterima → tepat dua `fee_overrides` baru: `applicant_id` pendaftar, `student_id` NULL, `scholarship_id` Beasiswa Tahfidz, `mode` `persen`, `value` 50, `valid_from` 2026-11-20, `valid_until` NULL, `approved_by` P, `reason` memuat "Tahfidz", untuk `UANG_PANGKAL` dan `SPP`. Item UANG_PANGKAL: base 5.000.000, discount 2.500.000, amount 2.500.000, `fee_override_id` terisi; SERAGAM tanpa potongan; tagihan `PSB_TERIMA` total 3.250.000. Activity `keuangan` `created` untuk setiap keringanan dengan causer P (test memanggil Action di bawah `actingAs(P)`, karena log otomatis `FeeOverride` mengambil causer dari user login).
- AC-13: Jalur YATIM (`nominal` 0, tanpa pemetaan) → tiga keringanan (UANG_PANGKAL, SERAGAM, KITAB), semua item amount 0 dengan discount = base, kedua tagihan total 0 berstatus `lunas`, tanpa keringanan SPP.
- AC-14: Keringanan khusus menang: pendaftar TAHFIDZ sudah punya keringanan UANG_PANGKAL `potongan` 1.000.000 (tanpa program, berlaku 2026-11-01 tanpa akhir) → penerimaan hanya membuat keringanan SPP, `scholarship.skipped` memuat UANG_PANGKAL `existing_override`, item UANG_PANGKAL amount 4.000.000. Program jalur nonaktif → tidak ada keringanan, `skipped` `scholarship_inactive`, `Log::warning` terpanggil, tagihan tetap dibuat tanpa potongan.
- AC-15: Snapshot: setelah penerimaan TAHFIDZ, ubah `Beasiswa Tahfidz` ke `persen` 100, ubah pemetaan jalur, dan ubah tarif UANG_PANGKAL → keringanan dan item tagihan yang sudah ada tidak berubah.
- AC-16: Idempotent: `GenerateAcceptanceInvoices::handle()` dua kali setelah penerimaan → tetap dua tagihan aktif dan jumlah keringanan sama; hasil kedua `existing_invoices` 2, `created_invoices` 0, `scholarship.skipped` berisi `already_applied` untuk tiap jenis biaya. `AcceptApplicant` kedua → `invalid_transition`, jumlah tagihan dan keringanan tetap. Setelah tagihan `PSB_KITAB` dibatalkan bendahara (`CancelInvoice`), `handle()` membuat satu tagihan `PSB_KITAB` baru bernomor baru dan tidak menyentuh `PSB_TERIMA`.
- AC-17: Tarif dan keuangan: tanpa tarif KITAB → hanya tagihan `PSB_TERIMA`, `skipped` memuat `missing_rate` untuk KITAB, `Log::warning` terpanggil; tarif khusus `W1` UANG_PANGKAL Rp4.500.000 di samping tarif umum → item 4.500.000; jenis `saat_diterima` nonaktif, `saat_daftar`, dan `bulanan` tidak ditagih. Modul keuangan mati → penerimaan berhasil tanpa keringanan dan tagihan, hasil `billing` NULL; setelah keuangan diaktifkan, `GenerateAcceptanceInvoices` membuat keringanan dan tagihan. `GenerateAcceptanceInvoices` untuk pendaftar `diverifikasi` atau `ditolak` → `applicant_not_accepted`.
- AC-18: Kegagalan tagihan tidak membatalkan penerimaan: container diberi `GenerateInvoiceNumber` palsu yang selalu mengembalikan nomor yang sudah dipakai → `AcceptApplicant` mengembalikan `billing_failed` true, status tetap `diterima`, tidak ada tagihan penerimaan setengah jadi, `Log::error` terpanggil; `GenerateAcceptanceInvoices::handle()` langsung dengan palsu yang sama → `RuntimeException`. Palsu yang bentrok sekali → tagihan tersimpan dengan nomor berikutnya.
- AC-18a: Status berubah di tengah pembuatan tagihan: (a) instance basi — pendaftar dimuat `diterima`, lalu baris diubah ke `ditolak` lewat `DB::table`; `GenerateAcceptanceInvoices::handle()` dan `ApplyTrackScholarship::handle()` dengan instance lama → `applicant_not_accepted`, tidak ada tagihan maupun keringanan baru; sama untuk pendaftar yang di-soft-delete. (b) Di sela grup — container diberi `GenerateInvoiceNumber` palsu (meneruskan ke yang asli) yang pada panggilan pertama (grup `PSB_TERIMA`) mendaftarkan `DB::afterCommit(fn () => DB::table('applicants')->where('id', $id)->update(['status' => 'ditolak']))`, sehingga status berubah tepat setelah transaksi grup pertama commit dan sebelum percobaan grup kedua → `GenerateAcceptanceInvoices::handle()` melempar `applicant_not_accepted`, tagihan `PSB_TERIMA` tersimpan, tagihan `PSB_KITAB` **tidak** dibuat. Lewat `AcceptApplicant` dengan palsu yang sama: `billing_failed` true, `Log::error` terpanggil, tanpa tagihan `PSB_KITAB`. Red-tester boleh mengganti mekanisme penyisipan selama urutannya terbukti: grup 1 commit → status berubah → percobaan grup 2 membaca status `ditolak` dari baris terkunci.
- AC-19: Cicilan: pembayaran Rp1.000.000 lewat `RecordPayment` pada tagihan `PSB_TERIMA` (pendaftar REGULER) → dialokasikan ke UANG_PANGKAL dulu (prioritas 20), tagihan `sebagian`; `acceptanceInvoices()` memuat kedua tagihan penerimaan dan tidak memuat tagihan formulir; `registrationInvoices()` tidak memuat tagihan penerimaan.

Penolakan dan pembatalan penerimaan
- AC-20: `RejectApplicant` oleh P (`admin-psb`, tanpa `cancel:invoice`, `actingAs(P)`) dari `diajukan` dengan tagihan formulir `belum` → status `ditolak`, tagihan formulir `batal` dengan activity `keuangan` beralasan `cancel_reason_rejected` dan causer P; hal sama lewat `rejectAction` di panel; tagihan formulir `lunas` → tidak berubah. Dari `cadangan` → `ditolak`.
- AC-21: Pembatalan penerimaan (TAHFIDZ, `diterima`, tanpa pembayaran tagihan penerimaan, formulir lunas) → status `ditolak`; kedua tagihan penerimaan `batal`; tagihan formulir `lunas` tetap; keringanan otomatis UANG_PANGKAL dan SPP `valid_until` 2026-11-20; keringanan khusus lain tetap; hasil `cancelled_invoices` 2, `ended_overrides` 2. Kasus hari yang sama: terima → batalkan → pulihkan (super-admin) → verifikasi → terima lagi, semuanya 2026-11-20 (keringanan lama `valid_until` 2026-11-20 masih berlaku hari itu → `already_applied`, tidak ada keringanan baru) → batalkan penerimaan lagi di hari yang sama → berhasil, status `ditolak`, tagihan penerimaan kedua `batal`, `ended_overrides` 0, tanpa `override_already_ended`. Keringanan otomatis yang `valid_until`-nya sudah dipendekkan bendahara ke 2026-11-20 juga dilewati.
- AC-22: Pembatalan ditolak tanpa perubahan apa pun (status, tagihan, keringanan): tagihan `PSB_TERIMA` punya pembayaran `diterima` → `acceptance_has_payments`; setelah pembayaran itu dibatalkan (`CancelPayment`, `paid_total` 0) → pembatalan berhasil. Pendaftar yang sudah punya santri (`students.applicant_id` terisi lewat factory) → `applicant_converted`.

Panel admin
- AC-23: `RolePermissionSeeder` membuat `decide:applicant` dan `reopen:applicant`; `admin-psb` mendapat persis permission F009 + F010 + `decide:applicant` (tanpa `reopen:applicant`); super-admin memiliki keduanya; dataset peran lain tidak berubah; test Shield untuk policy tetap lulus.
- AC-24: `ViewApplicant` (Livewire test) sebagai P: pendaftar `diverifikasi` → aksi `acceptAction`, `reserveAction`, `rejectAction`, `returnAction` tampil, `verifyAction` dan `cancelAcceptanceAction` tersembunyi; `diajukan` → hanya `verifyAction` dan `rejectAction`; `cadangan` → hanya `acceptAction` dan `rejectAction`; `diterima` → hanya `cancelAcceptanceAction` (dan `generateAcceptanceInvoicesAction` bila ada grup belum ditagih); `ditolak` → tidak ada aksi keputusan untuk P (termasuk `reopenAction`). User dengan `view-any:applicant` + `view:applicant` tanpa `decide:applicant` → semua aksi keputusan tersembunyi. Bendahara → 403 di halaman view (F010).
- AC-25: Menjalankan `acceptAction` (TAHFIDZ) → status `diterima`, notifikasi memuat jumlah tagihan dan nama beasiswa, seksi "Keputusan" menampilkan nama P dan waktu keputusan, seksi "Tagihan" menampilkan tagihan formulir dan kedua tagihan penerimaan ("Rp 3.250.000", "Rp 300.000"), seksi "Keringanan" menampilkan "Beasiswa Tahfidz". `reserveAction` tanpa catatan → error validasi pada `review_note`, status tetap. `cancelAcceptanceAction` saat ada pembayaran → pesan `acceptance_has_payments`, status tetap `diterima`. `verifyAction` dengan berkas belum lengkap → pesan `documents_incomplete`.
- AC-26: `generateAcceptanceInvoicesAction`: pendaftar `diterima` saat keuangan mati (tanpa tagihan), lalu keuangan diaktifkan → aksi tampil; dijalankan → tagihan dibuat dan aksi tersembunyi sesudahnya; tersembunyi tanpa `generate-invoices:applicant`, saat keuangan mati, dan untuk status selain `diterima`.
- AC-27: Cakupan: `admin-psb` bercakupan SD membuka view pendaftar SLTP → 404 (tidak bisa memutuskan); bercakupan SLTP menerima pendaftar SLTP → berhasil dengan keringanan `approved_by` user itu.

Halaman publik
- AC-28: `ApplicationStatus` (sesi pendaftar, `withoutVite`) setelah pendaftar REGULER diterima: memuat label status "Diterima", kalimat `status_help.diterima`, nomor kedua tagihan penerimaan, "Rp 5.750.000", "Rp 300.000", dan tagihan formulir; tidak memuat `review_note` (pendaftar dengan catatan "Catatan internal rahasia" tidak memunculkan teks itu). Tagihan `batal` tidak tampil.

Dashboard
- AC-29: `SummarizeApplicants` (travelTo 2026-11-20): gelombang `W1` (Y2027) berisi pendaftar 3 `diajukan`, 2 `diverifikasi`, 1 `diterima`, 1 `cadangan`, 1 `ditolak`, 1 `draft`, 1 terhapus, dan gelombang lama (Y2026) berisi 4 pendaftar → `academic_year` Y2027, `total` 8, `by_status` sesuai (draft tidak ada, kunci kelima status ada), pendaftar dengan `created_at` 2026-11-14 00:00 dihitung dan 2026-11-13 23:59 tidak dihitung di `last_7_days`. Tanpa gelombang → `academic_year` NULL dan semua 0. User bercakupan SLTP hanya menghitung pendaftar SLTP.
- AC-30: `PsbOverviewWidget`: `hasMetricSource()` true; `admin-psb` GET `/admin` → heading "Pendaftaran santri baru 2027/2028", stat "Total pendaftar" dengan angka benar, stat kelima status dengan label `pondok.applicant.statuses.*`, deskripsi ":count dalam 7 hari terakhir", link "Lihat pendaftar" ke index `ApplicantResource`. User dengan `view-dashboard` + `view-psb-metrics` tanpa `view-any:applicant` → widget tanpa link. Modul PSB mati → "Modul belum tersedia." tanpa angka (F018 AC-6 untuk PSB disesuaikan). Peran tanpa `view-psb-metrics` (bendahara) tidak melihat widget. Tanpa gelombang → stat 0 dan "Belum ada gelombang pendaftaran."

Teks
- AC-32: Pemulihan: pendaftar `ditolak` (sebelumnya `diajukan`, tagihan formulir `belum` dibatalkan saat ditolak) → `ReopenRejectedApplicant` oleh super-admin dengan catatan "Salah tolak" → status `diajukan`, `reviewed_by` super-admin, `review_note` "Salah tolak", tepat satu activity `psb` `updated` (`old.status` `ditolak`, `attributes.status` `diajukan`, `note`); tagihan formulir tetap `batal`, tidak ada tagihan atau keringanan baru, jumlah `invoices` dan `fee_overrides` sama dengan sebelum; aksi F010 "Buat tagihan pendaftaran" tampil lagi. Sebelum tagihan baru dibuat (KK dan AKTA `valid`): `MarkApplicantVerified` → `registration_fee_unpaid` (tagihan `batal` tidak dihitung lunas, padahal `registrationFeeSettled()` true dan unggah berkas terbuka); setelah aksi itu membuat tagihan formulir baru bernomor baru → masih `registration_fee_unpaid` selama `belum`; setelah dibayar penuh lewat `RecordPayment` → berhasil `diverifikasi`. Catatan kosong → `review_note_required`, status tetap `ditolak`. Pendaftar yang dibatalkan penerimaannya (TAHFIDZ) lalu dipulihkan, diverifikasi, dan diterima lagi pada 2026-12-05 → tagihan penerimaan baru (yang `batal` tetap ada) dan keringanan jalur baru `valid_from` 2026-12-05; keringanan lama tetap `valid_until` 2026-11-20.
- AC-33: Akses pemulihan: `reopenAction` tampil untuk super-admin pada pendaftar `ditolak` dan menjalankannya berhasil; tersembunyi untuk `admin-psb` (punya `decide:applicant`, tanpa `reopen:applicant`) dan untuk status selain `ditolak`; user dengan `decide:applicant` + `reopen:applicant` (diberikan manual) melihatnya.
- AC-31: Setiap kunci baru di bagian UI (`pondok.applicant.*` baru, `pondok.admission_track.*` baru, `pondok.psb.errors.*` baru, `pondok.dashboard.psb.*` baru) diterjemahkan, bukan kunci mentah (dataset).

## Rencana test

Konvensi `it('AC-n: ...')`. Helper baru di bagian terpisah akhir `tests/Pest.php` ("Helper penerimaan PSB (F011)"; periksa `git diff` dulu karena F009/F010 menyunting file yang sama): `penerimaanFixture()`, `penerimaanApplicant(string $status = 'diverifikasi', string $track = 'REGULER', string $unit = 'SLTP'): Applicant`, `penerimaanAccept(Applicant $applicant, ?User $by = null): array`. Factory: `FeeTypeFactory::onAcceptance()`; `ApplicantFactory` memakai state `status()` F010.

| AC | File | Nama test (usulan) |
|---|---|---|
| 1 | `tests/Feature/Database/PsbAcceptanceSchemaTest.php` | `creates the track fee type pivot` |
| 2–3 | `tests/Feature/Actions/Psb/SaveAdmissionTrackTest.php` (tambah) | `AC-2 (F011): maps fee types to a track` (dataset gagal), `AC-3 (F011): resolves scholarship fee types` |
| 4 | `tests/Feature/Filament/Resources/AdmissionTracks/AdmissionTrackResourceTest.php` (tambah) | `AC-4 (F011): edits the fee type mapping` |
| 5–10 | `tests/Feature/Actions/Psb/ApplicantDecisionTest.php` | `allows only valid transitions` (dataset), `records the decision`, `validates the review note`, `requires complete documents to verify`, `returns to submitted and reopens uploads`, `rejects deleted and stale applicants` |
| 11–19 | `tests/Feature/Actions/Psb/AcceptApplicantTest.php` | `bills acceptance fees`, `applies the track scholarship`, `falls back to every acceptance fee`, `keeps custom overrides and skips inactive programs`, `keeps snapshots`, `is idempotent`, `handles missing rates and the finance switch`, `keeps the acceptance when billing fails`, `stops billing when the status changes mid-way` (AC-18a), `allocates installments by priority` |
| 20–22 | `tests/Feature/Actions/Psb/RejectApplicantTest.php` | `rejects and cancels unpaid invoices without finance permissions`, `cancels an acceptance`, `cancels again on the same day`, `refuses to cancel paid or converted acceptances` |
| 23 | `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (ubah) | dataset `admin-psb`, `AC-23: creates the decide and reopen permissions` |
| 32 | `tests/Feature/Actions/Psb/ReopenRejectedApplicantTest.php` | `reopens a rejected applicant without restoring invoices`, `requires a note`, `bills again after reopening` |
| 33 | `tests/Feature/Filament/Resources/Applicants/ApplicantDecisionActionsTest.php` (tambah) | `AC-33: limits reopening to the reopen permission` |
| 24–27 | `tests/Feature/Filament/Resources/Applicants/ApplicantDecisionActionsTest.php` | sesuai AC |
| 28 | `tests/Feature/Livewire/Psb/ApplicationStatusTest.php` (tambah) | `AC-28 (F011): shows acceptance invoices without the review note` |
| 29 | `tests/Feature/Actions/Psb/SummarizeApplicantsTest.php` | `summarizes applicants of the latest admission year`, `respects the unit scope` |
| 30 | `tests/Feature/Filament/Widgets/PsbOverviewWidgetTest.php` | sesuai AC; test F018 yang mengassert widget PSB "Modul belum tersedia" saat saklar aktif disesuaikan (lihat di bawah) |
| 31 | `tests/Feature/Filament/PsbAccessTest.php` (tambah) | `translates every acceptance text key` (dataset) |

**Penyesuaian test lama (red-tester, mode RED):** F018 AC-6 mengassert `PsbOverviewWidget` selalu "Modul belum tersedia" saat saklar `psb` aktif dan `isModuleAvailable()` false. Setelah F011, perilaku itu hanya berlaku saat saklar mati atau tabel belum ada. Ubah dataset F018 AC-6 untuk PSB tanpa melemahkan assert widget lain. Bila F010 menguji judul seksi "Tagihan pendaftaran" di `ViewApplicant` atau `invoices_title` publik, sesuaikan ke judul baru.

## Urutan implementasi

1. Migration + relasi pivot `admission_track_fee_type`; `AdmissionTrack::scholarshipFeeTypes()`; AC-1, AC-3.
2. `SaveAdmissionTrack` (`fee_type_ids` + activity); AC-2.
3. Model `Applicant`: `TRANSITIONS`, `canTransitionTo`, `acceptanceInvoices`, `missingRequiredDocuments`.
4. `RecordApplicantDecision`, `MarkApplicantVerified`, `ReturnApplicantToSubmitted`, `ReserveApplicant`; AC-5 (sebagian), AC-6–10.
5. `ApplyTrackScholarship`.
6. `GenerateAcceptanceInvoices` (dengan ekstraksi logika bersama dari `GenerateRegistrationInvoices` bila dipilih; test F010 tetap hijau).
7. `AcceptApplicant`; AC-11–19.
8. `RejectApplicant`, `ReopenRejectedApplicant`; AC-5 lengkap, AC-20–22, AC-32.
9. `RolePermissionSeeder` + `ApplicantPolicy::decide`/`reopen`; AC-23.
10. `SummarizeApplicants`; AC-29.
11. Teks `lang/id/pondok.php`; design guide §3/§4.
12. Aksi keputusan (termasuk `reopenAction`) + infolist `ViewApplicant`, kolom `ListApplicants`; AC-24–27, AC-33.
13. Form/tabel `AdmissionTrackResource`; AC-4.
14. `ApplicationStatus` (semua tagihan aktif, teks `diterima`); AC-28.
15. `PsbOverviewWidget` (`hasMetricSource()` true, stat, link) + penyesuaian test F018; AC-30. F018 sudah `done` (dependensi).
16. Sinkronkan `docs/erd.md` (commit `docs(psb)`): tabel `admission_track_fee_type` dan arti pemetaan kosong, aturan transisi status, catatan "Saat status menjadi diterima ... membuat baris students" diganti "dibuat saat daftar ulang (F012)", `due_on` tagihan penerimaan, pembatalan penerimaan.
17. Pint, test terkait (AC-31 + test F010/F018 yang disentuh), checklist design guide §0 (cek visual `ViewApplicant`, `ManageAdmissionTracks`, dashboard admin-psb, dan `/psb/status` 360px).

Gerbang: langkah 1–10 hijau (AC tanpa UI) sebelum langkah 11.

## Risiko & pertanyaan terbuka

Setiap pertanyaan punya default yang dipakai bila user tidak memutuskan lain.

1. **Widget PSB bergantung F018.** *Diputuskan orkestrator (r1):* F018 ditambahkan ke `depends_on` F011 dan diimplementasikan lebih dulu.
2. **Jenis biaya penerima beasiswa jalur** (catatan F009). *Default:* pivot baru `admission_track_fee_type`; kosong = semua `saat_diterima` aktif; boleh memetakan `bulanan`/`tahunan` agar "SPP potong 50%" (contoh ERD) ikut pindah ke santri di F012. Keterbatasan: satu program per jalur sehingga satu mode/nilai untuk semua jenis biaya terpetakan ("uang pangkal gratis + SPP 50%" butuh keringanan khusus tambahan). Alternatif lebih kecil tanpa tabel baru: selalu semua `saat_diterima` (tidak bisa "uang pangkal saja" atau SPP).
3. **Masa berlaku keringanan jalur.** *Default:* mulai tanggal penerimaan, tanpa akhir (`valid_until` NULL, "sampai lulus" seperti ERD). Bendahara bisa mengakhirinya lewat `FeeOverrideResource`.
4. **Kuota penerimaan.** ERD hanya punya `admission_waves.quota` yang di F010 dipakai untuk pendaftaran. *Default:* tidak ada batas jumlah diterima di F011; panitia memakai status `cadangan`. Bila perlu, usulkan entri baru (kuota diterima per gelombang + jenjang).
5. **Syarat `diverifikasi`.** *Default:* formulir lunas + semua berkas wajib `valid`. Berkas opsional tidak dicek.
6. **Pemulihan pendaftar `ditolak`.** *Diputuskan orkestrator (r1):* transisi `ditolak` → `diajukan` lewat `ReopenRejectedApplicant`, permission terpisah `reopen:applicant` (bawaan hanya super-admin), catatan wajib, satu activity; tagihan `batal` dan keringanan yang diakhiri tidak dipulihkan. Risiko sisa: unggah berkas terbuka tanpa tagihan formulir aktif sampai panitia membuat tagihan baru (dicatat di Action dan AC-32).
7. **Penolakan membatalkan tagihan formulir yang belum dibayar.** *Default:* ya (paid_total = 0), agar tidak menjadi tunggakan abadi di laporan; tagihan yang sudah dibayar sebagian dibiarkan untuk bendahara.
8. **`applies_to` untuk biaya `saat_diterima`.** Pendaftar belum punya status mukim. *Default:* diabaikan (konsisten F010): semua pendaftar diterima ditagih semua biaya `saat_diterima`. Sekolah dengan biaya khusus asrama sebaiknya memakai jenis biaya terpisah setelah daftar ulang. Alternatif: hanya `applies_to = semua` (risiko kurang tagih yang tidak bisa diperbaiki karena tidak ada tagihan manual).
9. **Pemisahan tugas.** Keringanan jalur dibuat atas nama `admin-psb` yang menerima, tanpa persetujuan bendahara. *Default:* diterima karena program dan pemetaannya sudah diatur sebelumnya (program oleh bendahara, jalur oleh admin-psb), dan semua tercatat di activity log `keuangan`. Hal yang sama untuk pembatalan tagihan oleh `RejectApplicant`: `CancelInvoice` dijalankan sebagai langkah sistem di dalam Action tanpa memeriksa `cancel:invoice` pada user (keputusan orkestrator r2), dibatasi tagihan `paid_total = 0`, tercatat di activity `keuangan` dengan causer user dan alasan `cancel_reason_rejected` (AC-20).
10. **Keputusan massal.** *Default:* tidak di F011 (setiap penerimaan membuat tagihan; risiko salah klik besar). Usulan entri baru "psb-keputusan-massal".
11. **`due_on` tagihan penerimaan.** *Default:* `starts_on` tahun ajaran tujuan (2027-07-01) bila masih di depan, selain itu hari ini. Batas daftar ulang sebenarnya diatur F012 (`psb.min_paid_percent`).
12. **Catatan keputusan untuk pendaftar.** *Default:* internal; halaman publik hanya menampilkan status dan kalimat bantu. Alasan: catatan bisa memuat penilaian sensitif (UU PDP).
13. **Kegagalan tagihan setelah penerimaan.** *Default:* penerimaan tetap tersimpan, kegagalan dilaporkan dan diulang lewat aksi panel (pola F010). Alternatif satu transaksi penuh membuat penerimaan gagal hanya karena nomor tagihan bentrok.
14. **Tahun acuan widget.** *Default:* tahun ajaran tujuan gelombang terbaru; tanpa filter pilihan tahun (widget sederhana). Filter tahun lengkap ada di `ListApplicants`.
15. **Program jalur nonaktif saat penerimaan.** *Default:* keringanan dilewati dengan peringatan (bukan menggagalkan penerimaan), konsisten F009 yang membiarkan jalur tetap terhubung ke program nonaktif.
16. **Notifikasi keputusan ke wali.** Di luar (F021, WhatsApp). Wali memeriksa `/psb/status`.
17. **Usulan entri baru** (tidak dikerjakan di F011): "psb-keputusan-massal" (terima/tolak banyak pendaftar sekaligus), "psb-tes-seleksi" (jadwal dan nilai tes, menindaklanjuti F010 Risiko #19), opsional "psb-kuota-penerimaan".

## Riwayat revisi

- DRAFT 1 (product-manager): rencana awal. Jumlah AC: 31.
- DRAFT 3 (product-manager, Review #1 + keputusan orkestrator r2): major #1 — `RejectApplicant` hanya mengakhiri keringanan dengan `valid_until` NULL atau > hari ini; AC-21 ditambah kasus terima-ulang dan batal di hari yang sama. Major #2 — `ApplyTrackScholarship` dan setiap percobaan grup `GenerateAcceptanceInvoices` membaca status dan `deleted_at` dari baris terkunci (`applicant_not_accepted`); AC-18a baru (instance basi dan penolakan di sela grup). Minor: AC-12 memakai `actingAs(P)`; `SaveAdmissionTrack` menulis tepat satu activity per simpan (log otomatis dimatikan, format F009 + `fee_type_ids`), AC-2 diperluas; `registrationFeeRequired()`/`registrationFeeCleared()` baru sebagai syarat verifikasi (tagihan `batal` tidak dihitung lunas), AC-8 dan AC-32 diperluas; pembatalan tagihan PSB lewat `CancelInvoice` sebagai langkah sistem tanpa `cancel:invoice` (Action, Risiko #9, AC-20); AC-24 ditambah `cadangan`. Temuan #8 (gerbang dependensi) sudah ada di header; orkestrator memverifikasi ulang kontrak F010/F018 terhadap kode final sebelum RED. Jumlah AC: 34 (tambah AC-18a).
- DRAFT 2 (product-manager, keputusan orkestrator r1): Q1 — F018 kini dependensi F011 (header, langkah 15, Risiko #1). Q6 — transisi pemulihan `ditolak` → `diajukan` khusus super-admin: Action `ReopenRejectedApplicant`, permission `reopen:applicant` (`ApplicantPolicy::reopen`), aksi `reopenAction`, baris tabel transisi, ruang lingkup, AC-5 (6 Action), AC-23, AC-24 diperbarui; AC-32 dan AC-33 baru; Rencana test dan Urutan implementasi diperbarui. Default lain (2–5, 7–15) diterima; usulan entri baru tidak ditambahkan. Jumlah AC: 33.

## Review #1 — 2026-10-15

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | `RejectApplicant` langkah 5 vs `EndFeeOverride` | Pemilihan keringanan "`valid_until` NULL atau ≥ hari ini" memasukkan keringanan yang `valid_until`-nya tepat hari ini. `EndFeeOverride::handle` melempar `override_already_ended` bila `valid_until <= endOn` (app/Actions/Keuangan/EndFeeOverride.php), sehingga seluruh transaksi penolakan/pembatalan penerimaan di-rollback. Skenario nyata: terima, batalkan, pulihkan, terima lagi di hari yang sama (keringanan lama masih "berlaku hari ini" → `already_applied`), lalu tolak lagi di hari itu; atau bendahara sudah memendekkan masa berlaku ke hari ini. Penolakan tidak boleh gagal karena ini. | Pilih hanya keringanan dengan `valid_until` NULL atau `> today` (yang `= today` sudah berakhir hari ini, lewati). Tambahkan ke AC-21/AC-32: tolak setelah terima-ulang di hari yang sama berhasil dengan `ended_overrides` 0 untuk keringanan yang sudah berakhir hari ini. |
| 2 | major | `GenerateAcceptanceInvoices` langkah 1 dan 4 (retry) | Status `diterima` hanya dicek di langkah 1, di luar kunci. Penerimaan di-commit lebih dulu lalu tagihan dibuat di transaksi terpisah per grup, jadi `RejectApplicant` (yang mengunci dan membatalkan semua tagihan aktif) bisa berjalan di sela-selanya atau di sela dua grup; setelah itu generator membuat tagihan aktif baru untuk pendaftar `ditolak` (utang tak terlihat, data keuangan tidak bisa dihapus). Pola F010 mengecek ulang hanya tagihan grup, bukan status. Hal sama untuk `ApplyTrackScholarship`: pastikan cek `applicant_not_accepted` dilakukan pada baris yang dikunci, bukan pada instance masukan. | Di awal setiap percobaan transaksi (setelah `lockForUpdate`), cek ulang `status = diterima` dan tidak terhapus; bila tidak → `applicant_not_accepted` (di `AcceptApplicant` ditangkap sebagai `billing_failed`). Tambah AC: pendaftar diubah ke `ditolak` setelah langkah beasiswa dan sebelum tagihan grup kedua → tidak ada tagihan aktif baru. Sebutkan eksplisit bahwa `ApplyTrackScholarship` membaca status dari baris terkunci. |
| 3 | minor | AC-12 dan `ApplyTrackScholarship` | Activity `keuangan` keringanan datang dari log otomatis model `FeeOverride`, yang causer-nya berasal dari `auth()`, bukan dari `$by` (SaveFeeOverride tidak mencatat log manual). Pemanggilan Action tanpa sesi login membuat causer NULL, sehingga AC-12 "causer P" tidak konsisten. | Tulis di AC-12 dan Rencana test bahwa test memakai `actingAs(P)`; atau set `activity()->causedBy($by)` eksplisit. |
| 4 | minor | `SaveAdmissionTrack` (activity manual) vs F009 | F009 sudah membuat activity otomatis `psb` untuk `AdmissionTrack` (F009 AC-30). Activity manual pemetaan terpisah berarti perubahan `scholarship_id` + `fee_type_ids` sekaligus menghasilkan dua activity `updated`; AC-2 hanya menguji pemetaan sendirian. | Tetapkan perilakunya di AC-2 (dua activity diterima, atau satu gabungan) dan uji kasus gabungan. |
| 5 | minor | `ReopenRejectedApplicant` + `MarkApplicantVerified` | Setelah tagihan formulir `belum` dibatalkan saat ditolak lalu dipulihkan, `registrationFeeSettled()` true (hanya tagihan `batal`), sehingga pendaftar bisa langsung diverifikasi dan diterima tanpa bayar formulir bila panitia lupa membuat tagihan baru. Risiko #6 mencatatnya, tetapi tanpa AC. | Terima sebagai risiko, atau tambah AC yang menegaskan perilaku itu sebagai disengaja. |
| 6 | minor | Permission / Risiko #9 | `RejectApplicant` membatalkan tagihan lewat `CancelInvoice` atas nama `admin-psb` yang tidak punya `cancel:invoice`; Risiko #9 hanya membahas pemisahan tugas untuk keringanan. | Tambahkan pembatalan tagihan ke Risiko #9 (dibatasi paid_total = 0, tercatat di activity `keuangan`). |
| 7 | minor | AC-24 | Matriks aksi tidak menyebut status `cadangan` (seharusnya hanya `acceptAction` dan `rejectAction`). | Tambahkan baris `cadangan`. |
| 8 | minor | Header / dependensi | `depends_on` F010 dan F018 masih `todo` (F009 belum `done`). Rencana sudah memberi gerbang "RED menunggu F010 dan F018 `done`". | Orkestrator memeriksa gerbang sebelum RED dan memverifikasi ulang kontrak F010/F018 terhadap kode final (nama helper test, `getRequiredTables`). |

Catatan non-temuan: pivot `admission_track_fee_type` (restrict, unique pasangan, tanpa kolom nullable) portabel dan disinkronkan ke ERD di langkah 16; `groupBy('status')`, `activeBetween`, dan retry nomor tagihan di luar transaksi (pola F010) sudah benar; `FeeOverride` tanpa global scope sehingga cek keringanan pendaftar tidak terhalang `unit_user`; tidak ada dependensi Redis/Node; penerimaan tetap tersimpan saat tagihan gagal dan dapat diulang idempotent.

## Review #2 — 2026-10-15

Verdict: APPROVED

Verifikasi perbaikan Review #1: major #1 (RejectApplicant hanya mengakhiri keringanan `valid_until` NULL atau > hari ini, AC-21 kasus hari yang sama) tuntas; major #2 (status dan `deleted_at` dibaca dari baris terkunci di `ApplyTrackScholarship` dan tiap percobaan grup, AC-18a) tuntas; minor #3–#7 tertangani (actingAs, satu activity `SaveAdmissionTrack`, `registrationFeeCleared()`, CancelInvoice sebagai langkah sistem, AC-24 `cadangan`); #8 tetap gerbang orkestrator.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | `registrationFeeRequired()` | Memanggil `ResolveFeeRate` per verifikasi dan bergantung pada tarif saat itu: bila tarif formulir dihapus/diubah setelah pendaftaran, syarat berubah. Perilaku konsisten dan teruji di AC-8, tetapi jika tagihan formulir gagal dibuat saat daftar, pendaftar tertahan sampai panitia membuat tagihan (disengaja). | Cukup dicatat; pastikan pesan `registration_fee_unpaid` menyebut langkah "Buat tagihan pendaftaran". |
| 2 | minor | `SaveAdmissionTrack` mematikan log otomatis F009 | Mengubah perilaku Action F009 yang sedang diimplementasikan; risiko bentrok jika F009 belum final. | Saat RED/GREEN, pastikan test F009 AC-30 tetap hijau dan implementasi F009 sudah merge sebelum F011 mengubah Action ini. |
| 3 | minor | Dependensi | F010 dan F018 masih `todo`. | Gerbang RED tetap berlaku. |

Tidak ada blocker atau major.
