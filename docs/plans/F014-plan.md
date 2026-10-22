# F014 dokumen-surat-keluar — Surat keluar & QR verifikasi

Status rencana: APPROVED

Owner: orkestrator (klaim di `feature_list.json` dilakukan orkestrator, bukan PM). Dependensi: F013 dan F016 (keduanya rencana APPROVED, belum diimplementasi; F016 ditambahkan orkestrator ke `depends_on`). Red-tester tidak boleh mulai sebelum keduanya `done` (dari F013: modul `dokumen`, grup navigasi, grup route `EnsureModuleIsEnabled:dokumen`, penjaga hapus `EditStaff`; dari F016: key `school.*` yang diisi halaman profil sekolah). F014 tetap membaca `school.*` lewat `Setting::get` dengan fallback (AC-40), sehingga tidak bergantung pada isi settings.

## Ringkasan & tujuan

Admin sekolah menerbitkan surat keluar untuk santri: **surat keterangan aktif, surat pindah, surat rekomendasi, syahadah, dan kartu santri**. Setiap surat:

- mendapat **nomor surat otomatis, unik, dan berurutan** per jenis per tahun, dengan format yang bisa diatur lewat `settings` (bawaan `045/MAK/SKA/X/2026`);
- mendapat **kode verifikasi acak** dan **QR** berisi URL halaman publik verifikasi;
- isinya **dibekukan (snapshot)** saat terbit, sehingga cetak ulang menghasilkan isi yang sama walau data santri/penandatangan berubah;
- dicetak sebagai **halaman HTML siap cetak** (A4 untuk surat, ukuran kartu CR80 untuk kartu santri), pola kuitansi F007, tanpa dependensi PDF;
- tidak pernah dihapus: surat yang salah **dibatalkan** (status `batal`), nomornya tidak dipakai ulang, dan halaman verifikasi menyatakannya dibatalkan.

Pihak luar memindai QR (atau mengetik kode) di halaman publik `/verifikasi-surat/{kode}` tanpa login. Halaman menampilkan data minimal (UU PDP) dan dibatasi laju per IP.

Acuan: PRD 3.6 butir 4 ("Surat keluar ... dengan penomoran otomatis dan QR verifikasi keaslian"), PRD 4 (UU PDP), PRD 7 (format nomor surat masih terbuka); ERD "Dokumen" (`issued_letters`, catatan surat keluar); CLAUDE.md "Batasan deployment", "Konvensi kode & data", "Otorisasi"; design guide §4, §5, §6, §7, §8, §9, §10, §11, §12.

## Keputusan QR (tanpa dependensi baru)

| Opsi | Cara | Trade-off |
|---|---|---|
| **A (bawaan)** | Pakai `chillerlan/php-qrcode` ^5 yang **sudah terpasang** sebagai dependensi runtime `filament/filament` (untuk MFA; `composer.lock` memuat v5.0.5, bagian `packages`, bukan dev). Dibungkus `App\Services\QrCodeRenderer` yang menghasilkan SVG inline (tanpa GD/Imagick, tanpa JS, tanpa aset eksternal). | Tidak ada paket baru yang diunduh, tidak menambah ukuran rilis, bekerja di cPanel, QR ikut tercetak tanpa JS, bisa dites (decoder bawaan library). Risiko: bergantung pada dependensi transitif; bila Filament kelak membuang paket ini, QR hilang. Mitigasi: semua pemakaian lewat satu Service; dianjurkan user menyetujui menjadikannya `require` langsung di `composer.json` (versi lock sama, tidak ada unduhan baru) — lihat pertanyaan terbuka #1. Tanpa persetujuan, rencana tetap berjalan memakai paket transitif. |
| B | Encoder QR minimal ditulis sendiri (mode byte, ECC M, versi 1–6, Reed-Solomon, masking) ~400 baris. | Nol dependensi, tetapi risiko salah encode tinggi dan beban pemeliharaan; test butuh decoder (yang justru ada di opsi A). |
| C | Library JS QR di-bundle saat build (Vite) dan dirender di browser. | Dilarang design guide §12 tanpa persetujuan (library JS tambahan), halaman cetak butuh JS + `public/build`, tidak bisa dites di Pest. |
| D | Tanpa QR: cetak URL verifikasi + kode saja. | Paling sederhana, tetapi tidak memenuhi PRD ("QR verifikasi"). Tetap dipakai sebagai **pelengkap** di bawah QR (untuk yang tidak bisa memindai). |

Keputusan: **opsi A + teks URL & kode (D) sebagai pelengkap.** Bila `QrCodeRenderer` gagal (kelas tidak ada atau exception), halaman cetak tetap tampil tanpa QR (URL + kode tetap ada) dan kegagalan dicatat `Log::warning`. Test penjaga `class_exists(\chillerlan\QRCode\QRCode::class)` (AC-42) membuat upgrade Filament yang membuang paket ini gagal di test, bukan di produksi.

## Ruang lingkup

- Termasuk:
  - Migration `issued_letters` + model `IssuedLetter` + factory (state `cancelled`, `ofType`).
  - Action penomoran (`LetterNumbering`), pengaturan penomoran (`UpdateLetterNumbering`), penerbitan (`IssueLetter`, dengan retry nomor/kode unik di luar transaksi), pembatalan (`CancelIssuedLetter`), pencarian kode publik (`FindLetterByVerificationCode`).
  - Service `QrCodeRenderer` (SVG inline).
  - Resource Filament "Surat keluar" (daftar, terbitkan lewat modal, detail, cetak, batalkan, atur penomoran).
  - Halaman cetak HTML: satu layout surat A4 dengan bagian isi per jenis, dan satu layout kartu santri.
  - Halaman publik verifikasi (form kode + hasil) dengan rate limit.
  - Permission, policy, cakupan jenjang, penjaga hapus staff/user yang dirujuk surat.
  - Teks `lang/id/pondok.php`, design guide §4 (status surat), sinkronisasi `docs/erd.md`.
- Tidak termasuk (eksplisit):
  - PDF dan berkas arsip: `file_path` dibuat nullable dan **tidak diisi** di F014 (lihat pertanyaan #4; usulan entri baru).
  - Syahadah lengkap yang terhubung `tahfidz_exams` (F023, Fase 3). F014 hanya template minimal berbasis `purpose`.
  - Foto digital di kartu santri (belum ada fitur unggah foto santri; kartu memakai bingkai foto tempel).
  - Edit surat setelah terbit (koreksi = batalkan + terbitkan baru), hapus surat, penerbitan massal.
  - Penomoran terpisah per jenjang (pertanyaan #3), tanda tangan digital/elektronik tersertifikasi.
  - Surat untuk pendaftar PSB, staff, atau wali (hanya santri sesuai ERD).
  - Tautan "Surat keluar" di halaman santri (relation manager) — cukup filter di daftar surat.
  - Pemakaian layout portal publik F010/F017 (halaman verifikasi berdiri sendiri seperti kuitansi).

## Model data

### `issued_letters` — model `App\Models\IssuedLetter` (tanpa soft delete)

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| letter_no | string(50) unique | hasil format; tidak pernah dipakai ulang, termasuk surat `batal` |
| type | enum `keterangan_aktif`, `pindah`, `rekomendasi`, `syahadah`, `kartu_santri` | |
| sequence_year | unsignedSmallInteger | tahun kalender `issued_on` (penghitung reset per tahun) |
| sequence | unsignedInteger | nomor urut per (`type`, `sequence_year`) |
| student_id | foreignId → students, `restrictOnDelete` | santri soft delete tidak terpengaruh; force delete ditolak DB |
| signed_by_staff_id | foreignId → staff, `restrictOnDelete` | |
| issued_on | date | selalu hari ini (Asia/Jakarta), tidak diinput |
| purpose | string(255) nullable | keperluan/tujuan; wajib kecuali `kartu_santri` (selalu NULL) |
| verification_code | string(12) unique | 12 karakter dari alfabet tanpa huruf mirip (lihat model) |
| snapshot | json | isi surat yang dibekukan saat terbit (lihat bawah) |
| file_path | string nullable | **dicadangkan** untuk arsip PDF/pindaian, tidak diisi F014 |
| status | enum `terbit`, `batal`, default `terbit` | tambahan di luar ERD (pola `payments`) |
| cancelled_at | timestamp nullable | |
| cancelled_by | foreignId → users nullable, `restrictOnDelete` | |
| cancel_reason | string(255) nullable | tidak pernah ditampilkan di halaman publik |
| issued_by | foreignId → users, `restrictOnDelete` | |
| timestamps | | |

Index: unique (`type`, `sequence_year`, `sequence`), unique `letter_no`, unique `verification_code`, index (`student_id`, `type`), index (`status`, `issued_on`).

Alasan `restrictOnDelete` (bukan tabel keuangan): surat adalah dokumen legal yang sudah beredar; rujukannya tidak boleh hilang.

`snapshot` (array, cast `array`), dibentuk `IssueLetter`:

```
student: {name, nis, nisn, gender, birth_place, birth_date (Y-m-d|null), unit_name, classroom_name|null, academic_year_name|null, status}
signer:  {name, position, nip|null}
school:  {name, address|null, phone|null}
```

Sumber: santri + `unit` + `currentEnrollment.classroom` + tahun ajaran aktif; staff penandatangan; `Setting::get('school.name', config('app.name'))`, `school.address`, `school.phone` (key sama dengan kuitansi F007 dan profil F016). Logo **tidak** dibekukan: dibaca langsung dari `school.logo_path` saat cetak (pola kuitansi). Nama wali, NIK, alamat santri tidak masuk snapshot (data minimal). Santri tanpa rombel di tahun aktif atau tanpa tahun ajaran aktif → `classroom_name`/`academic_year_name` NULL, dan baris terkait tidak dicetak (AC-41).

`snapshot` adalah **data pribadi** (nama, NISN, tempat/tanggal lahir): tidak pernah masuk activity log maupun halaman publik. Retensi dan penghapusan snapshot atas permintaan subjek data (UU PDP) belum ditangani; menjadi keputusan lanjutan (pertanyaan terbuka #17).

### Model `IssuedLetter`

- `use HasFactory, HasUnitScope`. `applyUnitScope`: `whereIn('student_id', Student::withoutGlobalScopes()->whereIn('unit_id', $unitIds)->select('id'))` (santri soft delete tetap ikut, pola `Payment`).
- Konstanta: `TYPES`, `TYPES_REQUIRING_PURPOSE` (semua kecuali `kartu_santri`), `ALLOWED_STUDENT_STATUSES` (`keterangan_aktif`/`kartu_santri` → `aktif`; `pindah` → `aktif`, `pindah`; `rekomendasi`/`syahadah` → `aktif`, `lulus`), `STATUS_ISSUED = 'terbit'`, `STATUS_CANCELLED = 'batal'`, `STATUS_COLORS` (`terbit` success, `batal` danger), `CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ'` (31 karakter, tanpa 0/1/I/L/O), `CODE_LENGTH = 12`.
- Cast: `issued_on` date, `snapshot` array, `cancelled_at` datetime, `sequence`/`sequence_year` integer.
- Relasi: `student()` (BelongsTo, `withTrashed()`), `signer()` (Staff, `signed_by_staff_id`), `issuer()`, `canceller()` (User).
- `static newVerificationCode(): string` (`random_int` per karakter dari `CODE_ALPHABET`), `static normalizeVerificationCode(string): string` (huruf besar, buang selain `[A-Z0-9]`), `formattedVerificationCode(): string` (`ABCD-EFGH-JKMN`), `verificationUrl(): string` (`route('dokumen.letters.verify', code)`, absolut), `maskedStudentName(): string` dari **`snapshot.student.name`** (bukan relasi santri; kata pertama utuh, kata lain inisial + titik: `Muhammad Fauzan Akbar` → `Muhammad F. A.`; satu kata tetap utuh). Logika masker berupa `static maskName(string $name): string` agar bisa dites tanpa model.
- `Student::issuedLetters()`, `Staff::signedLetters()`, `User::issuedLetters()`, `User::cancelledLetters()` (HasMany).

### Settings penomoran (tanpa seed; bawaan di kode)

| Key | Bawaan | Arti |
|---|---|---|
| `letters.number_format` | `{seq}/{school_code}/{type_code}/{month_roman}/{year}` | template nomor |
| `letters.school_code` | NULL → diturunkan dari `school.name`: pecah di spasi dan `-`, buang karakter non-alfanumerik, ambil huruf pertama tiap kata, huruf besar, maks 6; kosong → `PONDOK` (`Ma'had Al-Kitab` → `MAK`) | kode lembaga |
| `letters.type_codes` | `keterangan_aktif` SKA, `pindah` SKP, `rekomendasi` SRK, `syahadah` SYH, `kartu_santri` KTS | kode per jenis (nilai tersimpan digabung dengan bawaan per jenis) |
| `letters.sequence_padding` | 3 | lebar nol di depan; nomor > 999 tetap tumbuh (tidak gagal) |

Token yang dikenal: `{seq}`, `{school_code}`, `{type_code}`, `{month}` (01–12), `{month_roman}` (I–XII), `{year}` (4 digit). Wajib ada `{seq}`, `{type_code}`, `{year}`. Bulan dan tahun dari `issued_on`.

Keunikan: dijamin **oleh unique index `letter_no`**, bukan oleh format semata. Penghitung per (jenis, tahun) membuat bentrok mustahil selama kode jenis tidak berpindah antarjenis di tahun yang sama. Karena itu `UpdateLetterNumbering` **menolak perubahan kode jenis** untuk jenis yang sudah punya surat (termasuk `batal`) di tahun berjalan (`type_codes`/`type_code_locked`), ditambah aturan kode antarjenis harus berbeda. Kasus sisa yang dibuat-buat (mis. format tanpa pemisah antara `{type_code}` dan `{seq}` dengan kode berakhiran angka) tetap tertangkap unique index dan berakhir `number_conflict` dengan pesan yang menyebut penyebabnya; tidak ada data rusak.

## Action class

Semua Action tidak memeriksa permission (dicek policy/UI), pola Action lain. Error validasi melempar `ValidationException` dengan pesan `pondok.dokumen.errors.<reason>` pada field yang disebut.

### `App\Actions\Dokumen\LetterNumbering`

- `settings(): array{format: string, school_code: string, type_codes: array<string,string>, padding: int}` — nilai tersimpan digabung bawaan.
- `defaultSchoolCode(string $schoolName): string`.
- `format(string $type, CarbonInterface $date, int $sequence, ?array $settings = null): string`.
- `nextSequence(string $type, int $year): int` — `IssuedLetter::withoutGlobalScopes()->where(type, year)->max('sequence') + 1` (termasuk `batal`, tanpa cakupan jenjang).
- `validate(array $data): array` — dipakai `UpdateLetterNumbering` (dan pratinjau UI); reason: `format_token_unknown`, `format_token_missing`, `format_character_invalid` (literal di luar `[A-Za-z0-9/._-]`), `format_too_long` (contoh nomor dengan seq 6 digit dan kode terpanjang > 50), `code_invalid` (`^[A-Z0-9.-]{1,10}$` setelah trim + huruf besar; school_code kosong → NULL), `type_codes_not_unique`, `type_code_locked` (kode jenis berubah padahal `IssuedLetter::withoutGlobalScopes()` punya surat jenis itu dengan `sequence_year` = tahun berjalan Asia/Jakarta; pesan menyebut jenisnya dan bahwa kode bisa diubah mulai tahun depan), `padding_invalid` (1–6).

### `App\Actions\Dokumen\UpdateLetterNumbering::handle(array $data, User $actor): void`

Input `number_format`, `school_code` (nullable), `type_codes` (5 kunci), `sequence_padding`. Validasi lewat `LetterNumbering::validate`; simpan empat key `letters.*` dalam satu transaksi; satu activity `dokumen`, event `numbering_updated`, causer `$actor`, properti `old`/`new` (tanpa data pribadi). Surat yang sudah terbit tidak berubah.

### `App\Actions\Dokumen\IssueLetter::handle(Student $student, array $data, User $issuer): IssuedLetter`

Input `type`, `signed_by_staff_id`, `purpose`. Urutan pemeriksaan:

1. `type` tidak dikenal → `type`/`letter_type_invalid`.
2. Santri di-soft-delete → `student`/`student_archived`; santri tidak terlihat oleh cakupan **user login** (`Student::query()->whereKey(...)->exists()` false) → `student`/`student_not_found`. Konsisten dengan Action modul lain (F013 `UploadDocument`, F007): cakupan mengikuti global scope user login, bukan `$issuer`; tanpa user login (console/queue) tidak disaring. Pemanggil UI selalu login sebagai `$issuer`; test cakupan wajib memakai `actingAs`.
3. Status santri tidak termasuk `ALLOWED_STUDENT_STATUSES[type]` → `student`/`student_status_not_allowed` (pesan menyebut status santri dan jenis surat).
4. Staff tidak ada atau `is_active` false → `signed_by_staff_id`/`signer_invalid`.
5. `purpose` di-trim; jenis di `TYPES_REQUIRING_PURPOSE` dan kosong → `purpose`/`purpose_required`; > 255 karakter → `purpose`/`purpose_too_long`; `kartu_santri` → disimpan NULL apa pun inputnya.

Lalu `issueWithRetry` (pola `RecordPayment::recordWithRetry`): `MAX_ATTEMPTS = 3`; tiap percobaan **satu** `DB::transaction` baru yang menghitung `nextSequence` (lewat `LetterNumbering` yang di-resolve dari container), membuat kode verifikasi baru lewat method publik `verificationCode(): string` (membungkus `IssuedLetter::newVerificationCode()`, seam untuk partial mock), memformat `letter_no`, menyimpan baris + snapshot, dan menulis satu activity `dokumen` event `issued` (properti `letter_no`, `type`; tanpa nama santri). `UniqueConstraintViolationException` ditangkap **di luar** transaksi (aman untuk Postgres) lalu diulang. Deadlock/lock wait (MySQL 1213/1205, Postgres 40P01, SQLite busy) juga diulang dalam loop yang sama: `QueryException` yang lolos pemeriksaan `DetectsConcurrencyErrors::causedByConcurrencyError()` (trait bawaan Laravel, dipakai `DB::transaction` sendiri) dianggap konflik sementara; exception lain dilempar ulang tanpa retry. Setelah 3 kali → `Log::error` lalu `letter_no`/`number_conflict` ("Nomor surat bentrok dengan surat lain. Periksa pengaturan kode jenis surat lalu coba lagi."). `issued_on` = hari ini Asia/Jakarta; `sequence_year` = tahunnya.

### `App\Actions\Dokumen\CancelIssuedLetter::handle(IssuedLetter $letter, User $user, string $reason): IssuedLetter`

`reason` di-trim, 1–255 → selain itu `reason`/`reason_invalid`. Dalam transaksi: `IssuedLetter::withoutGlobalScopes()->lockForUpdate()->findOrFail`; sudah `batal` → `letter`/`already_cancelled`. Set `status`, `cancelled_at`, `cancelled_by`, `cancel_reason`; satu activity `dokumen` event `cancelled` (properti `letter_no`, `reason`). Nomor, kode, dan snapshot tidak berubah.

### `App\Actions\Dokumen\FindLetterByVerificationCode::handle(string $code): ?IssuedLetter`

Normalisasi; panjang ≠ 12 atau ada karakter di luar `CODE_ALPHABET` → NULL tanpa query. Cari `IssuedLetter::withoutGlobalScopes()->with(['student' => withTrashed + withoutGlobalScopes])->where('verification_code', ...)`.

### `App\Services\QrCodeRenderer`

- `options(string $outputType = QROutputInterface::MARKUP_SVG): QROptions` — ECC `M`, quiet zone aktif, `outputBase64` false, `drawLightModules` false (modul terang tidak digambar, latar transparan), modul gelap `#000` (aman hitam-putih).
- `svg(string $data, string $label): string` — SVG inline untuk HTML5: keluaran library **dibuang prolog XML** (`<?xml ...?>`) dan **atribut `xmlns`/`xmlns:*`** (tidak diperlukan SVG inline HTML5 dan memuat URL `http://www.w3.org/...`), lalu ditambah `role="img"` dan `aria-label="{label}"` (di-escape) pada elemen `<svg>`. Hasil diawali `<svg`. Dipanggil lewat container (bisa di-mock). Label dari `pondok.letter.qr_label` ("QR verifikasi surat {nomor}").

## UI (Filament / Blade)

Wajib: baca design guide, jalankan alur §13 (skill `/anti-ui-slop` bila tersedia) dan checklist §0 sebelum menyatakan selesai.

### `IssuedLetterResource` — "Surat keluar" (`/admin/issued-letters`, halaman `ListIssuedLetters`, grup `pondok.navigation.dokumen`, sort 3, ikon `Heroicon::OutlinedEnvelope`)

- `BelongsToModule` dengan `getModule() = 'dokumen'`. Tidak ada halaman create/edit; tidak ada aksi edit/hapus.
- Tabel (pola §6): `letter_no` (searchable, `tabular-nums`), santri (nama dari relasi, deskripsi NIS; searchable nama/NIS), jenis (label `pondok.issued_letter.types.*`), `issued_on` (`j M Y`), penandatangan (nama snapshot), status (badge dari `STATUS_COLORS`). Toggleable tersembunyi: jenjang, keperluan, diterbitkan oleh. `defaultSort('id', 'desc')`, eager-load `student.unit`, `issuer`.
- Filter: jenis, status, jenjang (`->accessible()`), tahun (`sequence_year`).
- Header: aksi utama "Terbitkan surat" (permission `create`), aksi sekunder "Atur penomoran" (permission `configure-numbering`).
- "Terbitkan surat" (modal): santri (Select searchable lewat `Student::query()` terscope, tanpa yang terhapus, label `NIS — Nama`), jenis surat, penandatangan (staff aktif, label `Nama — Jabatan`), keperluan (Textarea, wajib; disembunyikan bila jenis `kartu_santri`, `live()`), teks bantu "Tanggal surat: hari ini." Error `ValidationException` dipetakan ke field. Berhasil → notifikasi success "Surat {nomor} diterbitkan." dengan tombol "Cetak surat" (URL cetak, tab baru).
- Aksi baris (`ActionGroup`): "Lihat detail" (infolist: nomor, jenis, santri, tanggal, keperluan, penandatangan, kode verifikasi terformat, URL verifikasi, status, diterbitkan oleh; bila batal: waktu, oleh, alasan), "Cetak surat" (URL, tab baru), "Batalkan surat" (danger, `requiresConfirmation`, isian alasan wajib, deskripsi akibat: "Surat yang dibatalkan dinyatakan tidak berlaku di halaman verifikasi. Nomor surat tidak dipakai ulang dan data tetap tersimpan."; tampil hanya bila `terbit` dan permission `cancel`).
- "Atur penomoran" (modal): format, kode lembaga (placeholder = kode turunan saat ini), lima kode jenis, lebar nomor urut; pratinjau `LetterNumbering::format('keterangan_aktif', hari ini, 45)` yang diperbarui saat isian berubah; helper text daftar token. Simpan → `UpdateLetterNumbering`; notifikasi "Pengaturan penomoran surat disimpan."
- Kosong: ikon + "Belum ada surat keluar." + aksi "Terbitkan surat" (bila berhak).

### Penjaga hapus

- `EditStaff`: `DeleteAction` tersembunyi bila staff punya `signedLetters()` (tanpa global scope), digabung dengan penjaga dokumen F013.
- `EditUser`: `DeleteAction` tersembunyi bila user punya `issuedLetters()` atau `cancelledLetters()` (tanpa global scope), digabung dengan penjaga pembayaran yang ada.
- `StaffTable`/`UsersTable`: bila punya aksi hapus baris atau bulk, terapkan penjaga yang sama (green-coder memeriksa).

### Halaman cetak (Blade mandiri, pola `keuangan/receipt.blade.php`)

Route panel `GET /admin/letters/{issuedLetter}/print`, name `dokumen.letters.print`, controller invokable `App\Http\Controllers\Dokumen\PrintIssuedLetter`: `Gate::authorize('view', $letter)`; data dari `snapshot` (bukan data santri terkini) + logo terkini; QR dari `QrCodeRenderer::svg($letter->verificationUrl())` (gagal → tanpa QR + `Log::warning`).

- `resources/views/dokumen/letter.blade.php` (A4, `@page { size: A4; margin: 20mm; }`): kop (logo, nama, alamat, telepon dari snapshot), judul per jenis (`pondok.letter.titles.*`), "Nomor: {letter_no}", isi dari partial `resources/views/dokumen/letters/<type>.blade.php` (teks `pondok.letter.bodies.*`), keperluan, tempat-tanggal (`issued_on` `j F Y`, misalnya `15 Oktober 2026`), blok tanda tangan (jabatan, ruang tanda tangan, nama, NIP bila ada), blok QR (SVG + "Pindai untuk memeriksa keaslian surat" + URL + kode terformat), footer "Diterbitkan oleh {nama issuer}" dan waktu cetak. Surat `batal` → kotak "DIBATALKAN" + waktu batal + alasan (halaman cetak bersifat internal, pola kuitansi; alasan tidak pernah tampil di halaman publik).
- `resources/views/dokumen/student-card.blade.php` (`@page { size: 85.6mm 54mm; margin: 0; }`): nama lembaga, "KARTU SANTRI", nama, NIS, jenjang, nomor surat kecil, bingkai pas foto 2×3 kosong, QR, nama penandatangan; batal → "DIBATALKAN".
- Syahadah (`letters/syahadah.blade.php`): judul "Syahadah", "diberikan kepada" nama, NIS, jenjang, "atas {keperluan}" — template minimal; rincian ujian tahfidz menunggu F023.
- Tanpa Vite, CDN, `<link>`, `<script src>`; hanya `onclick="window.print()"` pada tombol "Cetak" (pola kuitansi, disembunyikan saat print). Hitam-putih aman (`CanvasText`/`Canvas`), satu `h1`.

### Halaman publik verifikasi (Blade mandiri, tanpa login)

`routes/web.php`, grup middleware `[EnsureModuleIsEnabled::class.':dokumen', 'throttle:letter-verification']`:

- `GET /verifikasi-surat` name `dokumen.letters.verify-form` (`App\Http\Controllers\Dokumen\LetterVerificationController@form`): form satu input "Kode verifikasi" (label, `autocomplete="off"`), tombol "Periksa surat". Dengan query `kode` terisi → redirect 302 ke `/verifikasi-surat/{kode ternormalisasi}`; `kode` ada tetapi kosong → form + pesan "Masukkan kode verifikasi yang tercetak di surat."
- `GET /verifikasi-surat/{code}` name `dokumen.letters.verify` (`@show`, **tanpa** batasan regex): controller memotong input ke 64 karakter lalu memanggil `FindLetterByVerificationCode` (normalisasi + validasi alfabet/panjang di sana). Kode berisi spasi ter-encode, simbol, atau > 64 karakter → halaman 404 yang sama (bukan 404 bawaan Laravel).
  - Ditemukan, `terbit` → 200, judul "Surat asli dan berlaku": nama lembaga (snapshot), nomor surat, jenis, tanggal (`j M Y`), nama santri **dimasker** (`maskedStudentName`), nama + jabatan penandatangan. Khusus `kartu_santri`: "Status santri saat ini: Aktif" / "Tidak aktif" (dari santri terkini; terhapus = tidak aktif).
  - Ditemukan, `batal` → 200, judul "Surat ini telah dibatalkan" + tanggal batal + nomor, jenis; **tanpa** alasan batal dan tanpa nama santri.
  - Tidak ditemukan / format salah → **404** dengan halaman yang sama berisi "Kode verifikasi tidak ditemukan. Periksa kembali kode yang tercetak di surat." dan form.
  - Tidak pernah menampilkan NIS, NISN, tanggal lahir, keperluan, jenjang/kelas, nama issuer, atau alasan batal.
- Header privasi lewat middleware kecil `App\Http\Middleware\AddPrivacyHeaders` yang dipasang **paling luar** di grup (sebelum `EnsureModuleIsEnabled` dan `throttle`; bila daftar prioritas middleware menggeser urutan, `AddPrivacyHeaders` ditambahkan ke prioritas sebelum `EnsureModuleIsEnabled`), sehingga respons 200, 302, 404, 403 (modul mati), dan 429 (throttle) semuanya membawa `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store, private`. View juga memuat `<meta name="robots" content="noindex">`.
- `RateLimiter::for('letter-verification', fn (Request $r) => Limit::perMinute(30)->by($r->ip()))` di `AppServiceProvider::boot()`; terlampaui → 429.
- Gaya: Blade mandiri dengan CSS inline (pola kuitansi), mobile-first `max-width` ~ 36rem, `lang="id"`, sapaan "Anda", target sentuh ≥ 44px, fokus terlihat, tanpa Vite/CDN (tidak bergantung `public/build` maupun layout F010/F017).

### Teks `lang/id/pondok.php` (kunci baru)

- `navigation.dokumen` (dari F013, dipakai ulang).
- `issued_letter.{label, plural, letter_no, type, types.{keterangan_aktif,pindah,rekomendasi,syahadah,kartu_santri}, student, issued_on, purpose, signer, status, statuses.{terbit,batal}, verification_code, verification_url, issued_by, cancelled_at, cancelled_by, cancel_reason, year, issue, issue_hint_date, issued, print, cancel, cancel_description, cancel_reason_label, cancelled, empty, view}`.
- `letter_numbering.{title, format, format_help, school_code, school_code_help, type_codes, sequence_padding, preview, saved}`.
- `letter.{titles.*, bodies.*, number, place_date, signature_space, scan_hint, issued_by, printed_at, cancelled, cancelled_on, cancelled_reason, print_button, card_title, photo_frame, nip, qr_label, phone, classroom, academic_year}`.
- `letter_verification.{title, form_title, code_label, submit, code_required, valid_title, cancelled_title, not_found, school, letter_no, type, issued_on, student, signer, student_status, student_active, student_inactive, cancelled_on}`.
- `dokumen.errors.{letter_type_invalid, student_archived, student_not_found, student_status_not_allowed, signer_invalid, purpose_required, purpose_too_long, number_conflict, reason_invalid, already_cancelled, format_token_unknown, format_token_missing, format_character_invalid, format_too_long, code_invalid, type_codes_not_unique, padding_invalid}` — ditulis bersama Action masing-masing (bukan di langkah terakhir).

### Design guide §4

Tambah baris: `Surat keluar | terbit | success` dan `Surat keluar | batal | danger`.

## Permission & cakupan

### Permission baru

`RolePermissionSeeder::RESOURCE_MODELS` ditambah `IssuedLetter`; `EXTRA_RESOURCE_ACTIONS['IssuedLetter'] = ['cancel', 'configureNumbering']` → `view-any|view|create|update|delete|delete-any|cancel|configure-numbering :issued-letter`. `IssuedLetterPolicy` dibuat `shield:generate`, lalu ditambah `cancel(User, IssuedLetter)` (permission + status `terbit`) dan `configureNumbering(User)`. Test "every permission that a generated policy checks" tetap lulus.

### Pembagian ke peran

| Peran | IssuedLetter |
|---|---|
| super-admin | semua (termasuk `update`, `delete`, `delete-any` yang tidak dipakai UI) |
| admin-sekolah | `view-any`, `view`, `create`, `cancel`, `configure-numbering` |
| peran lain | — |

Dataset `grants each role exactly its permissions` di `RolePermissionSeederTest` diperbarui untuk `admin-sekolah` (tambahkan baris; file juga diubah F008/F009/F013/F016).

### Cakupan jenjang

`IssuedLetter` memakai global scope lewat santri (soft delete ikut). User bercakupan hanya melihat, mencetak, dan membatalkan surat santri jenjangnya; route cetak dengan binding terscope → 404 di luar cakupan; select santri di modal terscope. Penghitung nomor dan pemeriksaan integritas (penjaga hapus) **tanpa** scope. Halaman publik tanpa scope (tidak ada user).

### Saklar modul

`modules.dokumen.enabled` (F013, aktif bawaan). Mati → menu "Surat keluar" hilang, URL resource 403, route cetak 403, route publik verifikasi 403 (middleware sebelum binding/throttle).

## Kriteria penerimaan (bisa dites)

Fixture umum: waktu `2026-10-15 10:00 Asia/Jakarta`; `school.name` "Ma'had Al-Kitab"; jenjang SD dan SLTP; santri `S1` SD aktif "Muhammad Fauzan Akbar" NIS `2026000123` di rombel aktif; santri `S2` SLTP aktif; staff `T1` aktif "Ust. Hasan" jabatan "Kepala Sekolah" NIP `1987`; staff `T2` nonaktif; user `A` admin-sekolah tanpa cakupan; user `B` admin-sekolah bercakupan SLTP.

**Skema**
- AC-1: Setelah migration, `issued_letters` punya kolom di tabel model data, tanpa `deleted_at`; `Schema::getIndexes` memuat unique [`letter_no`], unique [`verification_code`], unique [`type`, `sequence_year`, `sequence`], index [`student_id`, `type`], index [`status`, `issued_on`]; `Schema::getForeignKeys` memuat FK `student_id`, `signed_by_staff_id`, `issued_by`, `cancelled_by` dengan `on_delete` `restrict` (case-insensitive). `status` bawaan `terbit`; `file_path` boleh NULL.
- AC-2: Constraint: dua baris `letter_no` sama → `UniqueConstraintViolationException`; dua baris (`type`, `sequence_year`, `sequence`) sama → exception yang sama; dua `verification_code` sama → exception yang sama; `forceDelete` santri yang punya surat → `QueryException`; hapus staff penandatangan → `QueryException`.

**Model**
- AC-3: `newVerificationCode()` 200 kali → semua panjang 12, hanya karakter `CODE_ALPHABET`, tidak ada duplikat. `normalizeVerificationCode(' abcd-efgh jkmn ')` = `ABCDEFGHJKMN`. `formattedVerificationCode()` = `ABCD-EFGH-JKMN`. `verificationUrl()` = `config('app.url').'/verifikasi-surat/ABCDEFGHJKMN'`.
- AC-4: `maskedStudentName()`: `Muhammad Fauzan Akbar` → `Muhammad F. A.`; `Fauzan` → `Fauzan`; spasi berlebih diabaikan. `STATUS_COLORS` = `terbit` success, `batal` danger.
- AC-5: Cakupan: `B` login → `IssuedLetter::query()` hanya surat `S2`; tanpa login atau `A` → semua; surat santri SLTP yang di-soft-delete tetap terlihat oleh `B`.

**Penomoran**
- AC-6: Tanpa settings `letters.*`: `LetterNumbering::format('keterangan_aktif', 2026-10-15, 45)` = `045/MAK/SKA/X/2026`; kode jenis bawaan lainnya `SKP`, `SRK`, `SYH`, `KTS`; `defaultSchoolCode`: `Ma'had Al-Kitab` → `MAK`, `Pondok Pesantren Al Ikhlas` → `PPAI`, `'''` → `PONDOK`; seq 1000 dengan padding 3 → `1000/...`; bulan Januari → `I`, Desember → `XII`.
- AC-7: Settings tersimpan (`format` `{type_code}-{seq}/{school_code}/{month}/{year}`, `school_code` `ALK`, `type_codes.pindah` `SPD`, padding 4) → `format('pindah', 2026-03-02, 7)` = `SPD-0007/ALK/03/2026`; `type_codes` tersimpan sebagian → jenis lain tetap kode bawaan.
- AC-8: `UpdateLetterNumbering` valid → keempat key tersimpan dan satu activity `dokumen` event `numbering_updated` causer aktor. Dataset tolak (field/reason): format dengan `{foo}` → `number_format`/`format_token_unknown`; tanpa `{seq}`, tanpa `{type_code}`, tanpa `{year}` → `format_token_missing`; berisi spasi atau `#` → `format_character_invalid`; format 45 karakter literal + token → `format_too_long`; `school_code` `MA K` atau 11 karakter → `school_code`/`code_invalid`; dua jenis berkode `SK` → `type_codes`/`type_codes_not_unique`; sudah ada surat `keterangan_aktif` 2026 (termasuk yang `batal`) lalu kode `keterangan_aktif` diubah `SKX` → `type_codes`/`type_code_locked`, sedangkan mengubah kode `syahadah` yang belum punya surat 2026 → berhasil, dan surat `keterangan_aktif` hanya di 2025 tidak mengunci kode di 2026; menukar kode `SKA`↔`SRK` saat keduanya punya surat 2026 → `type_code_locked`; padding 0 atau 7 → `sequence_padding`/`padding_invalid`. Saat ditolak, settings tidak berubah.
- AC-9: `school_code` kosong disimpan NULL dan kode turunan dipakai; huruf kecil `alk` disimpan `ALK`.

**Penerbitan**
- AC-10: `A` menerbitkan `keterangan_aktif` untuk `S1`, penandatangan `T1`, keperluan "Persyaratan beasiswa" → baris `terbit`, `letter_no` `001/MAK/SKA/X/2026`, `sequence` 1, `sequence_year` 2026, `issued_on` 2026-10-15, `issued_by` `A`, kode 12 karakter; `snapshot.student` memuat nama, NIS, nama jenjang, nama rombel, nama tahun ajaran; `snapshot.signer` = {"Ust. Hasan", "Kepala Sekolah", "1987"}; `snapshot.school.name` "Ma'had Al-Kitab"; satu activity `dokumen` event `issued` dengan properti `letter_no` dan `type` yang tidak memuat nama santri.
- AC-11 (**acceptance F014: unik dan berurutan**): tiga `keterangan_aktif` berturut → `001`, `002`, `003`; `rekomendasi` berikutnya → `001/MAK/SRK/X/2026`; membatalkan `003` lalu terbit lagi → `004` (nomor batal tidak dipakai ulang); `travelTo` 2027-01-04 → `001/MAK/SKA/I/2027`; `B` (bercakupan SLTP) menerbitkan untuk `S2` saat sequence tertinggi milik surat `S1` → `sequence` melanjutkan (tidak bentrok).
- AC-12: Snapshot dibekukan: setelah terbit, ubah nama `S1`, nama/jabatan `T1`, dan `school.name` → `snapshot` dan HTML cetak tetap memakai nilai lama. Mengubah settings penomoran tidak mengubah `letter_no` surat lama.
- AC-13: Dataset tolak (field/reason), tanpa baris dan tanpa activity: jenis `surat_cinta` → `type`/`letter_type_invalid`; `S1` soft-deleted → `student`/`student_archived`; `B` untuk `S1` (SD) → `student`/`student_not_found`; `keterangan_aktif` dan `kartu_santri` untuk santri `lulus` → `student_status_not_allowed`; `pindah` untuk santri `keluar` → `student_status_not_allowed`; `rekomendasi` untuk santri `lulus` → berhasil; `T2` → `signed_by_staff_id`/`signer_invalid`; staff id tak ada → `signer_invalid`; keperluan kosong/spasi untuk `keterangan_aktif`, `pindah`, `rekomendasi`, `syahadah` → `purpose`/`purpose_required`; keperluan 256 karakter → `purpose_too_long`.
- AC-14: `kartu_santri` dengan keperluan "apa saja" → tersimpan, `purpose` NULL, nomor `001/MAK/KTS/X/2026`.
- AC-15 (retry): partial mock `LetterNumbering` di container yang `nextSequence` pertama mengembalikan 1 (saat `001/MAK/SKA/X/2026` sudah ada) lalu implementasi asli → surat tercatat `002`, `nextSequence` dipanggil 2 kali. Mock yang selalu 1 → `ValidationException` `letter_no`/`number_conflict` setelah tepat 3 percobaan, tidak ada baris baru dan tidak ada activity baru. Partial mock `IssueLetter::verificationCode()` yang pertama mengembalikan kode yang sudah dipakai → surat tetap tercatat dengan kode lain. Partial mock `LetterNumbering::nextSequence` yang pertama melempar `QueryException` deadlock (pesan/kode `1213`, `SQLSTATE[40001]`) lalu implementasi asli → surat tercatat; `QueryException` lain (mis. kode `42S02`) → dilempar ulang tanpa percobaan kedua (`nextSequence` dipanggil 1 kali).

**Pembatalan**
- AC-16: `CancelIssuedLetter` dengan alasan "Salah keperluan" → `status` `batal`, `cancelled_at` now, `cancelled_by`, `cancel_reason` terisi; `letter_no`, kode, snapshot tidak berubah; satu activity `dokumen` event `cancelled`. Batalkan lagi → `letter`/`already_cancelled`; alasan kosong atau 256 karakter → `reason`/`reason_invalid`; keduanya tanpa perubahan data.

**QR**
- AC-17: `QrCodeRenderer::svg($url, 'QR verifikasi surat 001')` (renderer asli) → hasil `trim` diawali `<svg`; tidak memuat `<?xml`, `xmlns`, `http`, `data:`, `<script`, maupun `#fff`/`#ffffff`; elemen `<svg` memuat `role="img"` dan `aria-label="QR verifikasi surat 001"`; label berisi `"<` di-escape. Dengan `extension_loaded('gd')` (skip bila tidak): render PNG memakai `options(QROutputInterface::GDIMAGE_PNG)` lalu decode dengan decoder `chillerlan` → teks = `$url` (`https://pondok.test/verifikasi-surat/ABCDEFGHJKMN`).

**Cetak**
- AC-18: `GET /admin/letters/{id}/print` oleh `A` untuk `keterangan_aktif` `S1` → 200 `text/html`; teks memuat nama lembaga, judul `pondok.letter.titles.keterangan_aktif`, `001/MAK/SKA/X/2026`, `15 Oktober 2026`, nama dan NIS santri, nama rombel, "Persyaratan beasiswa", "Ust. Hasan", "Kepala Sekolah", URL verifikasi, kode terformat, nama `A`; tidak memuat kunci `pondok.` mentah dan tidak memuat "DIBATALKAN". `QrCodeRenderer` di-mock: `svg` dipanggil tepat sekali dengan argumen pertama `verificationUrl()` dan label yang memuat nomor surat, dan hasil mock (`<svg data-test="qr"></svg>`) ada di HTML.
- AC-19: Dengan `QrCodeRenderer` **asli** (tidak di-mock): HTML cetak memuat `<svg` ber-`role="img"`, tanpa `<?xml`, `@vite`, `/build/`, `<link `, `<script src`, `wire:`, dan URL `http(s)://` eksternal selain host aplikasi (pola test kuitansi; `www.w3.org` pun tidak boleh ada); `<html lang="id"`; tepat satu `<h1`.
- AC-20: Setiap jenis (dataset 5) mencetak 200 dengan judulnya masing-masing; `kartu_santri` memakai layout kartu (memuat `pondok.letter.card_title`, nama, NIS, jenjang, `@page` berukuran `85.6mm 54mm`); `syahadah` memuat keperluan.
- AC-21: Surat `batal` dicetak → memuat `pondok.letter.cancelled`, waktu batal, dan alasan. `QrCodeRenderer::svg` melempar exception → tetap 200, URL verifikasi dan kode tetap ada, tanpa `<svg`, satu log warning.
- AC-22: Akses cetak: `B` untuk surat `S1` → 404; `bendahara`/`kesantrian` → 403; tamu → redirect login panel; modul `dokumen` mati → 403 (juga untuk id yang tidak ada).

**Verifikasi publik**
- AC-23: Tamu `GET /verifikasi-surat/{kode}` surat `terbit` `S1` → 200, memuat `pondok.letter_verification.valid_title`, "Ma'had Al-Kitab", nomor surat, label jenis, `15 Okt 2026`, `Muhammad F. A.`, "Ust. Hasan", "Kepala Sekolah"; **tidak** memuat "Muhammad Fauzan Akbar", NIS, NISN, tanggal lahir, keperluan, nama rombel/jenjang, nama issuer. Kode huruf kecil dan berstrip (`abcd-efgh-jkmn`) → hasil sama. Nama santri diubah menjadi "Zainal Abidin" setelah terbit → halaman tetap `Muhammad F. A.` (dari snapshot) dan tidak memuat `Zainal`.
- AC-24: Surat `batal` → 200 dengan `cancelled_title`, tanggal batal, nomor; tanpa alasan batal dan tanpa nama santri (termasuk versi termasker).
- AC-25: Kode tidak ada, terlalu pendek, berisi karakter di luar alfabet (mis. `ABCDEFGHJK0O`), berisi simbol/spasi ter-encode (`ABCD%20EFGH!JKMN`, `<script>`), atau 200 karakter → 404 dengan halaman yang sama (pesan `not_found` + form, bukan halaman 404 bawaan), tidak ada error 500, input tidak dipantulkan tanpa escape.
- AC-26: `kartu_santri` → memuat `student_status` "Aktif"; setelah santri diubah `keluar` → "Tidak aktif"; santri di-soft-delete → "Tidak aktif" (halaman tetap 200).
- AC-27: User login `B` (bercakupan SLTP) membuka verifikasi surat `S1` (SD) → tetap 200 (halaman publik tanpa scope).
- AC-28: `GET /verifikasi-surat` → 200 berisi form berlabel; `?kode=abcd efgh jkmn` → 302 ke `/verifikasi-surat/ABCDEFGHJKMN`; `?kode=` → 200 dengan pesan `code_required`.
- AC-29: Header respons 200 (form dan hasil), 302 (redirect form), 404 (kode tidak ada), 403 (modul mati), dan 429 (throttle) semuanya memuat `X-Robots-Tag` berisi `noindex`, `Referrer-Policy: no-referrer`, `Cache-Control` berisi `no-store`; HTML memuat `<meta name="robots" content="noindex"`; tanpa Vite/CDN/`<script src` (pola AC-19).
- AC-30: Rate limit: 30 permintaan dari IP yang sama dalam satu menit → semuanya bukan 429; ke-31 → 429; IP lain pada menit yang sama → 200; setelah `travel(61)->seconds()` IP pertama → 200 lagi. Berlaku juga untuk `/verifikasi-surat` (form).
- AC-31: Modul `dokumen` mati → `/verifikasi-surat` dan `/verifikasi-surat/{kode valid}` → 403.

**Panel & akses**
- AC-32: `RolePermissionSeeder` membuat 8 permission `:issued-letter` (6 standar + `cancel` + `configure-numbering`); dataset peran diperbarui: `admin-sekolah` mendapat `view-any`, `view`, `create`, `cancel`, `configure-numbering`; peran lain selain super-admin tidak punya permission `:issued-letter`; tidak ada peran selain super-admin yang punya `update:issued-letter`, `delete:issued-letter`, `delete-any:issued-letter`. Test policy-permission tetap lulus.
- AC-33: Navigasi dan URL: `admin-sekolah` melihat menu "Surat keluar" di grup Dokumen dan `/admin/issued-letters` 200; `bendahara`, `kesantrian`, `admin-psb`, `admin-konten`, `musyrif`, `ustadz-tahfidz`, `petugas-pos` → menu tidak ada, URL 403 (dataset); modul mati → menu hilang dan URL 403 untuk `admin-sekolah` dan super-admin.
- AC-34: Livewire `ListIssuedLetters` sebagai `A`: aksi "Terbitkan surat" dengan `S1`, `keterangan_aktif`, `T1`, keperluan → baris tercipta, notifikasi success memuat nomor surat; keperluan kosong → error pada field `purpose`, tanpa baris; jenis `kartu_santri` → field keperluan tersembunyi dan surat tercipta. Opsi penandatangan tidak memuat `T2`.
- AC-35: Sebagai `B`: tabel hanya memuat surat `S2`; opsi pencarian santri di modal tidak memuat `S1`; mengirim id `S1` langsung → error `student`/`student_not_found`.
- AC-36: Aksi "Batalkan surat": tampil untuk surat `terbit`, tersembunyi untuk `batal`; dengan alasan → status `batal` dan notifikasi success; tanpa alasan → error, status tetap. User tanpa `cancel:issued-letter` (role kustom dengan `view-any` + `view`) → aksi tersembunyi. Tidak ada aksi edit atau hapus di tabel maupun bulk.
- AC-37: Aksi "Atur penomoran" sebagai `A`: form terisi nilai saat ini (bawaan bila kosong); simpan nilai valid → settings tersimpan, notifikasi success; nilai tidak valid → error pada field, settings tidak berubah. Role tanpa `configure-numbering:issued-letter` → aksi tersembunyi.
- AC-38: Penjaga hapus: `EditStaff` untuk `T1` yang menandatangani surat → header `delete` tersembunyi; staff tanpa surat (dan tanpa dokumen F013) → tampil. `EditUser` untuk user yang pernah menerbitkan atau membatalkan surat → `delete` tersembunyi. Dihitung tanpa cakupan: `B` membuka `EditStaff` `T1` yang hanya menandatangani surat `S1` (SD) → `delete` tetap tersembunyi.
- AC-39: Semua kunci `pondok.dokumen.errors.*` yang dipakai Action F014, `pondok.issued_letter.types.*`, `pondok.issued_letter.statuses.*`, `pondok.letter.titles.*`, dan `pondok.letter_verification.*` ada di `lang/id/pondok.php` (`__()` tidak mengembalikan kunci mentah); `issued_letter.statuses.terbit` = "Terbit", `batal` = "Batal".

**Tambahan revisi #1**
- AC-40: Fallback profil sekolah: tanpa satu pun key `school.*` (settings dikosongkan) → terbitkan surat berhasil, `snapshot.school` = {`config('app.name')`, NULL, NULL}; HTML cetak memuat `config('app.name')`, tidak memuat `<img`, tidak memuat label telepon (`pondok.letter.phone`) maupun baris alamat kosong; halaman verifikasi memuat `config('app.name')`. `school.logo_path` berisi path yang berkasnya tidak ada di disk `public` → cetak 200 tanpa `<img`.
- AC-41: Santri tanpa rombel di tahun ajaran aktif, dan kasus tanpa tahun ajaran aktif sama sekali → terbit berhasil, `snapshot.student.classroom_name` dan `academic_year_name` NULL; HTML cetak `keterangan_aktif` tidak memuat label rombel/tahun ajaran (`pondok.letter.classroom`, `pondok.letter.academic_year`) dan tidak memuat placeholder kosong (`: -` atau `:  `); nama dan NIS tetap tercetak.
- AC-42: Penjaga dependensi: `class_exists(\chillerlan\QRCode\QRCode::class)` dan `class_exists(\chillerlan\QRCode\QROptions::class)` bernilai true.

## Rencana test

Helper Pest baru di `tests/Pest.php` berprefiks `surat*` (`suratUser(role, unitCodes)`, `suratFixture()`, `suratStudent(...)`, `suratSigner(...)`, `suratIssue(...)`, `suratAssertRejected(...)`), pola helper `keuangan*`/`bayar*`.

| AC | File | Nama test (usulan) |
|---|---|---|
| 1, 2 | `tests/Feature/Database/DokumenLetterConstraintsTest.php` | `AC-1: creates issued letters with ERD columns and indexes`, `AC-2: enforces letter uniqueness and restrict deletes` |
| 3–5 | `tests/Feature/Models/IssuedLetterTest.php` | `AC-3: generates unambiguous verification codes`, `AC-4: masks student names`, `AC-5: scopes letters by unit` |
| 6, 7 | `tests/Feature/Actions/Dokumen/LetterNumberingTest.php` | `AC-6: formats default letter numbers` (dataset), `AC-7: honours stored numbering settings` |
| 8, 9 | `tests/Feature/Actions/Dokumen/UpdateLetterNumberingTest.php` | `AC-8: saves numbering settings`, `AC-8: rejects invalid numbering settings` (dataset), `AC-9: normalises school codes` |
| 10–15 | `tests/Feature/Actions/Dokumen/IssueLetterTest.php` | `AC-10: issues a letter with a snapshot`, `AC-11: numbers letters uniquely and sequentially`, `AC-12: freezes letter content`, `AC-13: rejects invalid letters` (dataset), `AC-14: issues a student card without purpose`, `AC-15: retries number and code conflicts` |
| 16 | `tests/Feature/Actions/Dokumen/CancelIssuedLetterTest.php` | `AC-16: cancels a letter without deleting it`, `AC-16: rejects invalid cancellations` |
| 17, 42 | `tests/Feature/Services/QrCodeRendererTest.php` | `AC-17: renders an inline html5 svg`, `AC-17: encodes the verification url`, `AC-42: keeps the qr library installed` |
| 18–22 | `tests/Feature/Http/Dokumen/IssuedLetterPrintTest.php` | `AC-18: prints a letter from its snapshot`, `AC-19: stays free of external assets`, `AC-20: prints every letter type` (dataset), `AC-21: marks cancelled letters and survives qr failure`, `AC-22: guards the print route` |
| 23–31 | `tests/Feature/Http/Dokumen/LetterVerificationTest.php` | `AC-23: shows minimal data for a valid letter`, `AC-24: reports cancelled letters`, `AC-25: answers unknown codes with 404`, `AC-26: shows current student card status`, `AC-27: ignores the unit scope of a logged-in user`, `AC-28: redirects the code form`, `AC-29: sends privacy headers`, `AC-30: rate limits verification`, `AC-31: closes verification when the module is off` |
| 32 | `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (dataset + test baru) | `AC-32: creates issued letter permissions`, `AC-32: keeps letter update and delete from every role but super-admin` |
| 33 | `tests/Feature/Filament/DokumenLetterAccessTest.php` | `AC-33: shows issued letters only to permitted roles` (dataset), `AC-33: hides issued letters when the module is off` |
| 34–37 | `tests/Feature/Filament/Resources/IssuedLetters/IssuedLetterResourceTest.php` | `AC-34: issues a letter from the panel`, `AC-35: scopes letters and students by unit`, `AC-36: cancels letters from the table`, `AC-37: configures letter numbering` |
| 38 | `tests/Feature/Filament/Resources/IssuedLetters/IssuedLetterDeleteGuardsTest.php` | `AC-38: protects staff and users referenced by letters` |
| 39 | `tests/Feature/Lang/DokumenLetterLangTest.php` | `AC-39: defines every letter translation` |
| 40, 41 | `tests/Feature/Http/Dokumen/IssuedLetterPrintTest.php` (+ `IssueLetterTest.php` untuk snapshot) | `AC-40: falls back when the school profile is empty`, `AC-41: omits missing classroom and academic year` |

Test HTTP halaman cetak dan publik tidak memerlukan `withoutVite()` karena view tidak memanggil Vite; AC-19/AC-29 memastikannya.

## Urutan implementasi

1. Migration `issued_letters` + model `IssuedLetter` (konstanta, cast, relasi, scope, helper kode/masking/URL) + factory + relasi di `Student`/`Staff`/`User`. Dalam commit yang sama perbarui `docs/erd.md` bagian Dokumen (`sequence_year`, `sequence`, `snapshot`, `status`/`cancelled_*`, `file_path` nullable dicadangkan, unique/index, settings `letters.*`). — AC-1–5.
2. `LetterNumbering` + `UpdateLetterNumbering` (termasuk `type_code_locked`) + kunci error terkait. — AC-6–9.
3. `IssueLetter` (retry di luar transaksi, snapshot, activity) + kunci error. — AC-10–15.
4. `CancelIssuedLetter` + kunci error. — AC-16.
5. `QrCodeRenderer` (buang prolog/xmlns, `drawLightModules` false, `role`/`aria-label`) + test penjaga. — AC-17, AC-42.
6. Permission + `IssuedLetterPolicy` (`shield:generate` + `cancel`, `configureNumbering`) + dataset seeder. — AC-32.
7. Route + controller + view cetak (surat A4, partial per jenis, kartu). — AC-18–22.
8. `FindLetterByVerificationCode`, middleware `AddPrivacyHeaders`, rate limiter, route publik + controller + view verifikasi. — AC-23–31.
9. `IssuedLetterResource` (tabel, filter, terbitkan, detail, cetak, batalkan, atur penomoran). — AC-33–37.
10. Penjaga hapus `EditStaff`/`EditUser` (+ tabel bila ada aksi hapus). — AC-38.
11. Sisa teks `lang/id`, design guide §4, alur §13 + checklist §0, Pint. — AC-39.

## Risiko & pertanyaan terbuka

Setiap butir punya default yang dipakai bila user tidak memutuskan lain.

1. **QR lewat paket transitif `chillerlan/php-qrcode`.** *Default:* dipakai lewat `QrCodeRenderer` tanpa mengubah `composer.json`. *Anjuran:* user menyetujui `composer require chillerlan/php-qrcode:^5.0` (tanpa unduhan baru, versi lock tetap 5.0.5) agar tidak rusak bila Filament membuang dependensinya. Opsi lain dan trade-off di bagian "Keputusan QR".
2. **Format nomor surat (PRD 7).** *Default:* `{seq}/{school_code}/{type_code}/{month_roman}/{year}` → `045/MAK/SKA/X/2026`, bisa diubah lewat "Atur penomoran"; kode jenis SKA/SKP/SRK/SYH/KTS. Format resmi lembaga pengguna perlu dikonfirmasi tetapi tidak memblokir.
3. **Reset dan cakupan penghitung.** *Default:* satu penghitung per jenis per **tahun kalender** `issued_on`, satu lembaga (tidak per jenjang). Bila SD/SLTP/SLTA memakai kop dan nomor sendiri, perlu entri baru (token `{unit_code}` + penghitung per jenjang). Reset per tahun ajaran atau per bulan tidak didukung.
4. **`file_path` (ERD: "PDF yang dicetak").** *Default:* kolom nullable, tidak diisi; isi surat dibekukan di `snapshot` sehingga cetak ulang identik tanpa menyimpan berkas. Arsip PDF atau pindaian surat bertanda tangan basah → usulan entri baru.
5. **Tanggal surat.** *Default:* selalu hari ini, tidak bisa mundur/maju, agar nomor berurutan juga kronologis. Pencatatan surat lama (backdate) tidak didukung.
6. **Status santri per jenis.** *Default:* keterangan aktif & kartu → `aktif`; pindah → `aktif`/`pindah`; rekomendasi & syahadah → `aktif`/`lulus`.
7. **Data di halaman verifikasi (UU PDP).** *Default:* nama santri termasker, nomor, jenis, tanggal, penandatangan, nama lembaga; kartu santri menambah status aktif terkini. Tanpa NIS, tanggal lahir, keperluan, alasan batal.
8. **Kartu santri baru tidak membatalkan kartu lama.** *Default:* tidak otomatis; admin membatalkan kartu lama secara manual (mis. kartu hilang).
9. **Peran.** *Default:* hanya `admin-sekolah` (dan super-admin) yang menerbitkan, membatalkan, dan mengatur penomoran; `kesantrian` tidak mendapat akses.
10. **Modul mati.** *Default:* halaman verifikasi publik ikut 403 (surat lama tidak bisa diverifikasi selama modul mati).
11. **Format tanggal di surat cetak** memakai bulan penuh (`15 Oktober 2026`) karena dokumen resmi, sedangkan halaman verifikasi dan panel memakai `15 Okt 2026` sesuai §5. Bila reviewer menginginkan §5 di mana pun, cukup ganti format di view.
12. **`APP_URL` harus benar** di instalasi cPanel karena QR memuat URL absolut; salah konfigurasi menghasilkan QR yang mengarah ke host lain. Dicatat untuk panduan instalasi F019, bersama **trusted proxies**: di belakang proxy/Cloudflare `request()->ip()` bisa seragam (semua pengunjung berbagi limit 30/menit) atau dipalsukan bila `trustProxies` dibuka sembarangan (sudah ada di notes F019). Ruang kode 31^12 (~59 bit) membuat enumerasi tidak praktis walau limiter lemah.
13. **Logo kop dibaca langsung** (tidak dibekukan); F016 menghapus berkas logo lama saat diganti, sehingga cetak ulang surat lama memakai logo terbaru.
14. **Halaman verifikasi berdiri sendiri** (CSS inline, tanpa layout portal). F017 boleh memindahkannya ke layout portal nanti asalkan AC-29 tetap lulus.
15. **Syahadah minimal**: hanya berbasis `purpose`; integrasi `tahfidz_exams.certificate_no` menjadi bagian F023.
16. **Koordinasi file bersama** (`RolePermissionSeeder`, `RolePermissionSeederTest`, `lang/id/pondok.php`, `tests/Pest.php`, `EditStaff`, `EditUser`, `AppServiceProvider`): tambahkan, jangan menimpa perubahan F009/F013/F016.
17. **Retensi snapshot (UU PDP).** *Default:* surat dan snapshot disimpan tanpa batas waktu seperti arsip surat; penghapusan atas permintaan subjek data menjadi keputusan lanjutan. Halaman publik tetap mengungkap jenis surat dan status aktif kartu santri kepada pemegang kode (diterima sebagai default #7).

Usulan entri baru (tidak dikerjakan di F014, hanya dilaporkan): (a) arsip PDF/pindaian surat (`issued_letters.file_path`) setelah keputusan library PDF; (b) penomoran surat per jenjang bila dibutuhkan; (c) foto santri digital di kartu santri.

## Riwayat revisi

- 2026-10-15 — DRAFT awal (product-manager).
- 2026-10-15 — Revisi #1 menanggapi Review #1:
  - #1 (major): `QrCodeRenderer::svg($data, $label)` membuang prolog XML dan `xmlns`, `drawLightModules` false, menambah `role="img"` + `aria-label`; AC-17 ditulis ulang (tanpa `<?xml`, `xmlns`, `http`, `#fff`); AC-19 tegas memakai renderer asli.
  - #2: F016 masuk dependensi (oleh orkestrator); AC-40 (fallback `school.*` kosong, logo hilang) dan AC-41 (santri tanpa rombel/tahun aktif).
  - #3: klaim "unik secara konstruksi" diganti; `UpdateLetterNumbering` menolak perubahan kode jenis yang sudah punya surat di tahun berjalan (`type_code_locked`), diuji di AC-8; sisa kasus tertangkap unique index → `number_conflict`.
  - #4: retry juga untuk deadlock/lock wait lewat `causedByConcurrencyError`; kasus ditambahkan ke AC-15.
  - #5: route verifikasi tanpa regex; validasi di controller/Action; AC-25 diperluas (simbol, spasi, 200 karakter).
  - #6: middleware `AddPrivacyHeaders` paling luar; AC-29 mencakup 302/403/429.
  - #7: trusted proxies dicatat di risiko #12 (untuk F019).
  - #8: `maskedStudentName()` dari `snapshot.student.name`; AC-23 menguji nama diubah setelah terbit.
  - #9: cakupan `IssueLetter` mengikuti user login (konsisten modul lain), didokumentasikan; test memakai `actingAs`.
  - #10: snapshot dinyatakan data pribadi; retensi menjadi pertanyaan #17.
  - #11: test penjaga `class_exists` (AC-42).
  - #12: `file_path` nullable sudah dijadwalkan di sinkronisasi ERD langkah 1.

## Review #1 — 2026-10-15

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | `QrCodeRenderer` vs AC-17/AC-19/AC-21 | Keluaran bawaan `chillerlan/php-qrcode` v5.0.5 (dicoba di vendor) diawali `<?xml version="1.0" encoding="UTF-8"?>`, memuat `xmlns="http://www.w3.org/2000/svg"`, dan menggambar modul terang dengan `fill="#fff"` (tidak transparan). Prolog XML di dalam `<body>` HTML tidak valid. `xmlns` membuat AC-19 ("tanpa `http(s)://` eksternal selain host aplikasi") gagal bila test memakai renderer asli. AC-17 memberi pengecualian namespace tetapi AC-19 tidak, sehingga red-tester dan green-coder bisa buntu (green-coder tidak boleh mengubah test). Rencana juga hanya menyebut "latar transparan" tanpa opsi (`drawLightModules=false`). | Tetapkan di rencana: `svg()` membuang prolog XML dan atribut `xmlns` (SVG inline HTML5 tidak membutuhkannya), memakai `drawLightModules=false`, dan menambah `role="img"` + `aria-label`. AC-17 diubah: tanpa `<?xml`, tanpa `http`. AC-19 tegas dijalankan dengan renderer asli (bukan mock) agar kontrak ini teruji. |
| 2 | minor | Dependensi (`feature_list.json` F014 `depends_on` hanya F013) | Rencana memakai `school.name/address/phone/logo_path` dari F016/F007, tetapi F016 `todo` dan bukan dependensi. Tidak ada AC untuk kondisi settings `school.*` belum diisi. | Tambah AC kecil: tanpa `school.*` nama jatuh ke `config('app.name')`, alamat/telepon/logo disembunyikan, tidak error. Kasus snapshot santri tanpa rombel/tahun ajaran aktif (nilai NULL) juga perlu satu AC (cetak tidak memuat baris kosong). |
| 3 | minor | Penomoran, "unik secara konstruksi" | Klaim tidak sepenuhnya benar. Validasi `type_codes_not_unique` hanya memeriksa kode saat ini. Menukar kode dua jenis (atau memakai kode lama jenis lain) dalam tahun berjalan membuat `letter_no` baru bentrok dengan surat lama (`letter_no` unique global), dan retry tidak membantu karena `nextSequence` hanya per jenis. Hasilnya `number_conflict` permanen sampai pengaturan dikembalikan. DB tetap aman (unique), jadi bukan data rusak. | Ubah klaim di rencana. Opsional: `UpdateLetterNumbering` menolak perubahan kode jenis bila sudah ada surat jenis itu di tahun berjalan, atau `number_conflict` menyebut penyebab. Tambahkan satu AC untuk perilaku yang dipilih. |
| 4 | minor | `IssueLetter` retry (CLAUDE.md konkurensi, Postgres) | Pola retry di luar transaksi sudah benar untuk Postgres (unique violation membatalkan transaksi) dan tiap percobaan membaca ulang max. Kekurangan: MySQL bisa melempar deadlock/lock wait (1213/1205) saat dua insert unique bersamaan, itu `QueryException` biasa dan tidak diulang. Tidak ada percobaan jeda. Test hanya lewat mock (wajar di SQLite). | Tangkap juga deadlock lewat `DB::transaction($cb, 2)` atau cek kode error, atau catat sebagai risiko diterima (volume surat rendah). |
| 5 | minor | Route verifikasi `where('code', '[A-Za-z0-9-]{1,32}')` | Kode yang tidak cocok regex (spasi, >32 karakter, simbol) tidak masuk route sehingga mendapat 404 bawaan Laravel tanpa form, tanpa header `noindex`/`no-store`, dan saat modul mati menjadi 404 (bukan 403). Bertentangan dengan teks "format salah -> 404 halaman yang sama" dan AC-29. | Hapus batasan regex (normalisasi dan validasi di Action) atau tambah AC untuk kode >32 karakter dan simbol yang mengharapkan halaman 404 yang sama beserta header. |
| 6 | minor | Header privasi, AC-29 | Header hanya diuji untuk 200/404. Respons 429 (throttle) dan 403 (modul mati) dihasilkan middleware sebelum controller dan tidak membawa header tersebut. | Pasang header lewat middleware kecil di grup route (di luar throttle) atau tambahkan 429 ke AC-29. |
| 7 | minor | Rate limit tanpa Redis | `Limit::perMinute(30)->by($request->ip())` memakai cache `database` (cukup, tanpa Redis; tiap hit menulis ke tabel `cache`). Di cPanel di belakang proxy/Cloudflare `ip()` bisa seragam atau bisa dipalsukan karena `bootstrap/app.php` tidak mengatur `trustProxies`. Ruang kode 31^12 (~59 bit) membuat enumerasi tidak praktis. | Catat di panduan instalasi F019 (trusted proxies). Tidak memblokir. |
| 8 | minor | `maskedStudentName()` | Sumber nama tidak disebut (snapshot atau relasi `student`). Memakai relasi membuat halaman publik menyimpang dari snapshot dan menampilkan nama baru/terhapus. | Tegaskan: dari `snapshot.student.name`; tambahkan ke AC-23 (nama santri diubah setelah terbit, masker tetap nama lama). Kartu santri tetap memakai status terkini (sudah ada di AC-26). |
| 9 | minor | `IssueLetter` langkah 2 | Pemeriksaan cakupan memakai `Student::query()` (user dari `auth()`), bukan `$issuer`. Bila Action dipanggil dari command/queue atau test tanpa `actingAs`, cakupan `$issuer` diabaikan. | Terapkan cakupan `$issuer` secara eksplisit, atau dokumentasikan bahwa Action mengikuti user login. Pastikan helper test AC-13/AC-35 memakai `actingAs`. |
| 10 | minor | UU PDP (PRD 4) | Pemasker nama sudah baik. Namun halaman publik tetap mengungkap jenis surat (mis. surat pindah) dan status aktif kartu santri bagi siapa pun yang memegang kode; snapshot menyimpan tempat/tanggal lahir dan NISN tanpa batas retensi. | Diterima sebagai default PM #7. Catat di rencana bahwa snapshot adalah data pribadi (tidak masuk activity log, sudah benar) dan retensi/penghapusan menjadi keputusan lanjutan. |
| 11 | minor | Risiko dependensi transitif | `filament/filament` memang `require chillerlan/php-qrcode ^5.0` (composer.lock v5.0.5, paket runtime), `decoder` dan GD tersedia, dan pembungkus tunggal serta fallback tanpa QR sudah memitigasi. Risiko diterima. | Orkestrator menanyakan `composer require` eksplisit ke user. Bila ditolak, tambah satu test penjaga (`class_exists(QRCode::class)`) agar upgrade Filament yang membuang paket ini gagal lewat test, bukan di produksi. |
| 12 | minor | Pemetaan ERD | ERD hanya punya `letter_no`, `type`, `student_id`, `signed_by_staff_id`, `issued_on`, `purpose`, `verification_code`, `file_path`, `issued_by`. Tambahan rencana (`sequence_year`, `sequence`, `snapshot`, `status`, `cancelled_*`) sudah dijadwalkan masuk `docs/erd.md` di langkah 1 dan tidak melanggar konvensi (uang tidak ada, `restrictOnDelete`, tanpa soft delete). | Tidak ada tindakan; pastikan `file_path` ditandai nullable di ERD. |

Yang sudah baik: semua AC feature_list ("Nomor surat unik dan berurutan") dan PRD 3.6 butir 4 tercakup (AC-11, AC-15, AC-23-31); retry di luar transaksi; tanpa dependensi baru/Redis/Node; query portabel (`max`, `json`, `enum`); pembatalan tanpa hapus dengan nomor tidak dipakai ulang; cakupan jenjang tidak dipakai di penghitung nomor dan halaman publik; cetak HTML tanpa aset luar; urutan implementasi kecil dan berurutan; test wajib CLAUDE.md (akses per peran, cakupan jenjang, dashboard tidak terdampak) terpetakan.

Yang perlu dibenahi sebelum APPROVED: temuan #1 (major). Temuan lain boleh diperbaiki sekaligus pada revisi yang sama.

## Review #2 — 2026-10-16

Verdict: APPROVED

Verifikasi perbaikan Review #1: major #1 (SVG tanpa prolog/`xmlns`/`#fff`, AC-17 dan AC-19 dengan renderer asli) teratasi dan konsisten antar-AC. Minor #2-#12 semuanya tertangani (AC-40/41/42, `type_code_locked`, retry concurrency, route tanpa regex, `AddPrivacyHeaders` paling luar, masker dari snapshot, cakupan `IssueLetter` didokumentasikan).

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Dependensi | F013 dan F016 masih `todo`; red-tester tidak boleh mulai sebelum keduanya `done` (sudah dicatat di rencana untuk F013). | Orkestrator memastikan F016 `done` sebelum RED. |
| 2 | minor | `AddPrivacyHeaders` (403/429) | Header pada 403/429 bergantung pada exception yang dirender di pipeline sehingga lewat middleware terluar. Itu perilaku Laravel (`Routing\Pipeline::handleException`) dan AC-29 menguji 403/429, jadi aman; bila test merah, periksa prioritas middleware, bukan menghapus AC. | Tidak ada tindakan. |
| 3 | minor | Retry deadlock (AC-15) | `causedByConcurrencyError` adalah method `protected` trait `DetectsConcurrencyErrors`; Action harus memakai trait itu sendiri. Deteksi "SQLite busy" bergantung pada teks pesan Laravel. | Green-coder memakai trait; AC sudah memakai pesan `1213`/`40001`. |
| 4 | minor | Retensi snapshot (Q17) | Default tanpa batas berisi tempat/tanggal lahir dan NISN santri (anak). | Tetap ditanyakan ke user di laporan akhir (sudah direncanakan). |
| 5 | minor | `composer require` eksplisit | Tidak diubah sekarang; AC-42 menjadi jaring pengaman. | Tetap ditanyakan ke user. |

Tidak ada blocker atau major. Tidak ditemukan masalah baru pada urutan implementasi, cakupan jenjang, portabilitas, atau pemetaan AC-ke-test.
