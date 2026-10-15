# F006 keuangan-generator-tagihan — Generator tagihan bulanan

Status rencana: APPROVED

Owner: tim-agent-fase1. Dependensi: F004 (`done`), F005 (`done`).

## Ringkasan & tujuan

Setiap tanggal 1, sistem membuat tagihan bulanan untuk semua santri aktif secara otomatis. Untuk setiap jenis biaya bulanan yang aktif dan berlaku bagi santri (semua / mukim / nonmukim), generator mencari tarif dasar (`ResolveFeeRate`, F004), menerapkan keringanan yang aktif di bulan itu (`ResolveFeeOverride` + `ApplyFeeOverride`, F005), lalu menyimpan hasilnya sebagai item tagihan. Item dengan `billing_group` yang sama masuk ke satu tagihan (`invoices`), sehingga sekolah bisa memilih tagihan gabungan atau terpisah tanpa mengubah kode.

Generator aman dijalankan ulang (idempotent): tagihan tidak pernah ganda, dan item yang sudah ada tidak pernah diubah karena nominalnya adalah snapshot. Santri yang tarifnya belum diatur dicatat di log dan dilewati untuk jenis biaya itu. Generator berjalan dari satu cron (`schedule:run`), dari command artisan, dan dari tombol di panel. Bendahara bisa melihat daftar tagihan (menghormati cakupan jenjang) dan membatalkan tagihan yang belum dibayar (status `batal`, tidak dihapus).

Acuan: PRD 3.5 ("Tagihan bisa digabung atau dipisah", "Generator tagihan bulanan otomatis dan aman dijalankan ulang"), PRD 5 butir 3; ERD "Keuangan & Tagihan" (`invoices`, `invoice_items`, tabel mode gabungan/terpisah, catatan mukim dan snapshot), "Urutan penentuan nominal per jenis biaya" (pseudocode), tabel constraint (`invoices` unique dan check, `invoice_items` unique, index `status, period`), "Tabel bawaan" (`activity_log` untuk audit `invoices`); design guide §4 (badge tagihan), §5 (uang, periode), §7 (state), §8 (konfirmasi generate massal dan batalkan).

## Ruang lingkup

- Termasuk:
  - Migration, model, factory: `invoices`, `invoice_items`.
  - Action di `app/Actions/Keuangan/`: `GenerateMonthlyInvoices`, `GenerateInvoiceNumber`, `CancelInvoice`.
  - Command artisan `keuangan:generate-invoices {--period=}` dan jadwalnya di `routes/console.php` (tanggal 1, lewat cron `schedule:run` yang sudah ada).
  - Global scope cakupan jenjang pada `Invoice` (lewat santri, override `applyUnitScope`).
  - Panel Filament `InvoiceResource` ("Tagihan"): daftar, halaman detail (item tagihan), aksi header "Buat tagihan bulanan", aksi baris "Batalkan tagihan".
  - Permission dan policy `Invoice` (termasuk aksi tambahan `generate` dan `cancel`), pembagian ke `bendahara`, dataset `RolePermissionSeederTest` diperbarui.
  - Activitylog log `keuangan`: satu ringkasan per eksekusi generator dan perubahan status saat pembatalan.
  - Relasi baru di model lama: `Student::invoices()`, `FeeType::invoiceItems()`, `FeeOverride::invoiceItems()`, `AcademicYear::invoices()`, `User::generatedInvoices()`.
  - Tombol Delete di `EditUser` disembunyikan juga bila user tercatat sebagai `generated_by` tagihan (FK baru `restrictOnDelete`, pola F005).
  - Teks `lang/id/pondok.php` (bagian `invoice`).
- Tidak termasuk (eksplisit):
  - Pembayaran, alokasi cicilan, kuitansi, dan perubahan `paid_total` / `paid_amount` (F007). F006 hanya menyediakan kolom dengan nilai awal 0 dan method `Invoice::syncStatus()`.
  - Tagihan PSB (`saat_daftar`, `saat_diterima`), tabel `applicants`, FK `invoices.applicant_id` (F010–F012). F006 menyediakan kolom `applicant_id` nullable tanpa FK (pola `fee_overrides.applicant_id`) dan unique (`applicant_id`, `billing_group`, `active_marker`).
  - Jenis biaya frekuensi `tahunan` dan `manual`, serta tagihan manual dari panel. Generator hanya memproses `bulanan`.
  - Mengedit tagihan `batal` atau memulihkannya ke status aktif. Koreksi dilakukan dengan menjalankan generator lagi setelah pembatalan (tagihan baru bernomor baru, lihat AC-55).
  - Mengubah atau menghapus item tagihan dari UI. Tidak ada aksi Edit atau Delete.
  - Laporan, rekap tunggakan, dan widget dashboard (F008, F018). Index (`status`, `period`) disiapkan untuk keduanya.
  - Pembatasan edit keringanan yang sudah dirujuk `invoice_items` (pertanyaan F005 #6). Snapshot sudah menjamin tagihan lama tidak berubah; tetap default F005.
  - Antrean (queue) untuk aksi manual di panel. Generator berjalan sinkron (lihat Risiko #4).
  - Halaman pengaturan untuk `keuangan.due_day`; nilainya dibaca dari `settings` dengan default.

## Model data

Semua tabel memakai `timestamps`. Tidak ada soft delete. Uang `unsignedBigInteger` rupiah. Semua FK `restrictOnDelete`. Perbandingan tanggal memakai string `Y-m-d` (portabel SQLite/MySQL/Postgres).

### `invoices` — model `App\Models\Invoice`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| invoice_no | string(20), unique | `INV-YYYYMM-NNNNN`, lihat `GenerateInvoiceNumber` |
| student_id | foreignId → students, nullable, `restrictOnDelete` | null hanya untuk tagihan pendaftar (F010) |
| applicant_id | unsignedBigInteger, nullable, index | **tanpa FK** sampai F010 menambah `applicants`; komentar migration menyebut ini |
| academic_year_id | foreignId → academic_years, `restrictOnDelete` | tahun ajaran yang memuat `period` |
| period | date | selalu tanggal 1 bulan tagihan |
| billing_group | string | salinan `fee_types.billing_group` saat dibuat |
| total | unsignedBigInteger, default 0 | jumlah `invoice_items.amount` |
| paid_total | unsignedBigInteger, default 0 | cache terbayar, diisi F007 |
| status | enum `belum`, `sebagian`, `lunas`, `batal`, default `belum` | |
| due_on | date | jatuh tempo |
| generated_by | foreignId → users, nullable, `restrictOnDelete` | null bila dari scheduler/command |

Constraint dan index:
- Generated column stored `active_marker` = `CASE WHEN status <> 'batal' THEN 1 END` (`unsignedTinyInteger`, nullable, `storedAs`, pola `room_assignments.active_marker`). Bernilai 1 untuk tagihan aktif (`belum`/`sebagian`/`lunas`) dan NULL untuk tagihan `batal`. Diisi database; tidak `fillable`, tidak di-cast, tidak dicatat activitylog. Perubahan status lewat `CancelInvoice` otomatis memperbarui kolom ini.
- unique (`student_id`, `period`, `billing_group`, `active_marker`) nama `invoices_student_period_group_active_unique`. Hanya satu tagihan aktif per santri, periode, dan grup. Tagihan `batal` (marker NULL) tidak saling bentrok dan tidak menghalangi tagihan aktif baru, jadi kombinasi yang sama boleh punya banyak tagihan `batal` ditambah satu aktif. Baris pendaftar (`student_id` NULL) juga tidak bentrok di sini; mereka dijaga unique berikutnya.
- unique (`applicant_id`, `billing_group`, `active_marker`) nama `invoices_applicant_group_active_unique`. Tagihan santri (`applicant_id` NULL) tidak saling bentrok di ketiga driver.
- **Penyimpangan sadar dari pola `COALESCE(col, 0)` CLAUDE.md:** kedua unique di atas sengaja memakai semantik NULL-berbeda pada `student_id`, `applicant_id`, dan `active_marker`, karena tagihan pendaftar tidak boleh bentrok dengan tagihan santri (dan sebaliknya) dan tagihan `batal` tidak boleh saling bentrok. Migration wajib memuat komentar yang menjelaskan ini (pola komentar `room_assignments`).
- `period` selalu diisi lewat model/factory dengan `startOfMonth()` (cast `date`), tidak lewat `DB::table`, karena SQLite menyimpan cast `date` sebagai `Y-m-d 00:00:00` sedangkan MySQL/Postgres memotong ke tanggal; unique tetap konsisten selama semua jalur memakai model.
- Perubahan ERD atas keputusan orkestrator: baris constraint `invoices` di `docs/erd.md` diperbarui sesuai dua unique di atas (lihat Riwayat revisi), dan entitas `invoices` di diagram Mermaid ditambah kolom `active_marker` (r3).
- index (`status`, `period`).
- **Pemilik wajib** (`student_id` atau `applicant_id` terisi): Blueprint Laravel 13 tidak menyediakan check constraint portabel (dan SQLite tidak bisa menambah check lewat ALTER), jadi dijaga di model: event `saving` melempar `LogicException` bila keduanya null. Keputusan sama dengan F005 (`fee_overrides`).

Konstanta: `STATUS_UNPAID = 'belum'`, `STATUS_PARTIAL = 'sebagian'`, `STATUS_PAID = 'lunas'`, `STATUS_CANCELLED = 'batal'`, `STATUSES`. Cast: `period`, `due_on` `date`; `total`, `paid_total` integer.

Relasi: `student()`, `academicYear()`, `generator()` (BelongsTo `User`, kolom `generated_by`), `items()` HasMany `InvoiceItem`.

Method:
- `recalculateTotal(): void` — `total = sum(items.amount)` dari database, lalu `syncStatus()`, lalu simpan.
- `syncStatus(): void` — tidak mengubah `batal`; selain itu `paid_total >= total` → `lunas` (termasuk total 0), `paid_total = 0` → `belum`, lainnya → `sebagian`. Dipakai ulang oleh F007.
- `isCancellable(): bool` — status bukan `batal` dan `paid_total = 0`.
- `isOverdue(): bool` — status `belum`/`sebagian` dan `due_on < today()` (dihitung, tidak disimpan).
- `statusColor(): string` dan `statusLabel(): string` — satu tempat untuk pemetaan design guide §4 (`lunas` success, `sebagian` warning, `belum` gray, `batal` danger; `isOverdue()` menimpa warna menjadi danger dengan label "Lewat jatuh tempo").

Cakupan jenjang: `use HasUnitScope`; override `applyUnitScope($builder, $unitIds)` menjadi `whereIn('invoices.student_id', Student::withoutGlobalScopes()->withTrashed()... ->whereIn('unit_id', $unitIds)->select('id'))`. Subquery tanpa soft-delete scope agar tagihan santri yang diarsipkan tetap terlihat oleh user berjenjang yang berhak. Tagihan tanpa santri (pendaftar, F010) tidak terlihat oleh user berjenjang sampai F010 memperluas override ini. Generator tidak terpengaruh karena berjalan tanpa user login atau memakai `withoutUnitScope()` (lihat Action).

Activitylog: `LogsActivity` dengan `useLogName('keuangan')`, `logOnly(['status'])`, `logOnlyDirty()`, `dontSubmitEmptyLogs()`. Pembuatan massal oleh generator dibungkus `activity()->withoutLogs(...)` (spatie/laravel-activitylog 4.12) agar tidak membuat ratusan baris; sebagai gantinya ada satu ringkasan per eksekusi (lihat Action). Pembatalan tercatat lewat **satu jalur** di `CancelInvoice` (lihat Action): penyimpanan dibungkus `withoutLogs`, lalu satu log manual `event('updated')` berisi `old.status`, `attributes.status`, dan `reason`. Jadi tepat satu baris per pembatalan.

### `invoice_items` — model `App\Models\InvoiceItem`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| invoice_id | foreignId → invoices, `restrictOnDelete` | |
| fee_type_id | foreignId → fee_types, `restrictOnDelete` | |
| description | string | `"<nama jenis biaya> <Oktober 2026>"`, periode via `translatedFormat('F Y')` locale `id` |
| base_amount | unsignedBigInteger | tarif normal (snapshot) |
| discount_amount | unsignedBigInteger | keringanan (snapshot) |
| amount | unsignedBigInteger | yang harus dibayar (snapshot) |
| rate_source | enum `angkatan`, `jenjang`, `manual` | `angkatan` bila `FeeRate::isCohortRate()`, `jenjang` bila tarif lain, `manual` bila tidak ada tarif dan nominal berasal dari keringanan mode nominal |
| fee_override_id | foreignId → fee_overrides, nullable, `restrictOnDelete` | dari `ApplyFeeOverride` |
| paid_amount | unsignedBigInteger, default 0 | hasil alokasi pembayaran, diisi F007 |

- unique (`invoice_id`, `fee_type_id`).
- Konstanta `RATE_SOURCE_COHORT = 'angkatan'`, `RATE_SOURCE_UNIT = 'jenjang'`, `RATE_SOURCE_MANUAL = 'manual'`, `RATE_SOURCES`. Cast semua kolom uang integer.
- Relasi `invoice()`, `feeType()`, `feeOverride()`.
- Invarian: `base_amount - discount_amount = amount` (dijamin `ApplyFeeOverride`).
- Tanpa activitylog per item (snapshot, tidak pernah diubah oleh F006).

### Factory
`InvoiceFactory` (santri aktif, tahun ajaran, `period` tanggal 1 bulan ini, `billing_group` `BULANAN`, `invoice_no` unik berurutan, state `cancelled()`, `paid()`, `partiallyPaid(int $amount)`); `InvoiceItemFactory` (konsisten dengan invarian).

## Action class

### `App\Actions\Keuangan\GenerateMonthlyInvoices`
`handle(CarbonInterface $period, ?User $generatedBy = null): array`

Dependensi masuk lewat konstruktor (property promotion): `ResolveFeeRate`, `ResolveFeeOverride`, `ApplyFeeOverride`, `GenerateInvoiceNumber`. Action selalu di-resolve dari container (command, panel, test), tidak pernah `new`, sehingga spy/mock di AC-32, AC-60, dan AC-61 bekerja.

Docblock wajib menyebut: eksekusi yang terputus (timeout, proses dimatikan) aman diulang karena idempotent; tagihan per kelompok yang sudah di-commit tetap ada, sisanya dibuat pada eksekusi berikutnya.

Input:
- `$period`: tanggal apa pun; dinormalisasi ke tanggal 1 bulannya (`CarbonImmutable`, zona `Asia/Jakarta`).
- `$generatedBy`: user panel; null untuk scheduler/command.

Output (array, pola F005 tanpa DTO):
```php
array{
  period: string,                // 'Y-m-d' tanggal 1
  created_invoices: int,         // tagihan baru
  added_items: int,              // item yang ditambahkan ke tagihan lama (rerun)
  existing_invoices: int,        // tagihan yang sudah ada dan tidak berubah
  skipped: list<array{student_id: int, fee_type_id: int, reason: string}>,
}
```
`reason` ∈ `missing_rate` (tarif belum diatur dan tidak ada keringanan nominal), `invoice_locked` (tagihan aktif sudah ada dan sudah ada pembayaran, sehingga item yang belum ada tidak ditambahkan).

Prasyarat (gagal → `ValidationException` pada key `period`, tanpa membuat data):
1. Ada `academic_years` dengan `starts_on <= period <= ends_on`; bila tidak ada: pesan `pondok.invoice.errors.no_academic_year`.
2. Tidak ada eksekusi lain untuk periode yang sama: `Cache::lock("keuangan:generate-invoices:{Y-m}", 1800)` (30 menit, jauh di atas perkiraan durasi ratusan–ribuan santri); bila gagal diambil: `pondok.invoice.errors.already_running`. Lock dilepas di `finally`. Bila proses mati sebelum `finally` (mis. `max_execution_time` request panel), lock kedaluwarsa sendiri setelah TTL; pesan `already_running` menyebut "coba lagi beberapa saat lagi". Store cache bawaan (`database`/`file`) mendukung lock, tanpa Redis.

Status saklar modul **tidak** dicek di Action (dicek di command dan di panel), agar Action tetap murni dan bisa dites.

Algoritma:
1. Ambil jenis biaya: `FeeType::active()->where('frequency', 'bulanan')->orderBy('priority')->orderBy('id')->get()` sekali. Bila kosong, kembalikan hasil nol.
2. Santri: `Student::withoutUnitScope()->active()` (status `aktif`, tidak soft-deleted) dengan `entered_on` null atau `<= akhir bulan period`, `->with('activeRoom')`, `->chunkById(200)`. Cakupan jenjang user login tidak pernah menyaring generator, meskipun dipanggil dari panel oleh user berjenjang.
3. Per chunk, muat sekali:
   - tagihan **aktif** yang sudah ada: `Invoice::withoutUnitScope()->whereIn('student_id', ids)->whereDate('period', period)->where('status', '<>', 'batal')->with('items:id,invoice_id,fee_type_id')->get()`, diindeks per (`student_id`, `billing_group`). Tagihan `batal` diabaikan seluruhnya oleh generator (tidak dianggap ada, itemnya tidak dihitung);
   - pasangan (`student_id`, `fee_type_id`) yang punya keringanan beririsan bulan itu: satu query `FeeOverride::whereIn('student_id', ids)->whereIn('fee_type_id', feeTypeIds)->activeBetween(awal bulan, akhir bulan)` hanya untuk memilih pasangan yang perlu dicek. Pemilihan keringanan yang dipakai tetap lewat `ResolveFeeOverride::forStudent($student, $feeType, $period)`, tidak memfilter ulang (kontrak F005).
4. Per santri, mukim = `activeRoom !== null` saat generator berjalan. Jenis biaya berlaku bila `applies_to` = `semua`, atau `mukim` dan santri mukim, atau `nonmukim` dan santri nonmukim.
5. Per jenis biaya berlaku:
   - Tarif: `ResolveFeeRate::handle($feeType, $academicYear, unit_id, cohort_id, activeRoom?->dormitory_id)`, **dimemo** per (`fee_type_id`, `unit_id`, `cohort_id`, `dormitory_id`) selama satu eksekusi. Santri dengan kombinasi sama tidak memicu query ulang.
   - Keringanan: `ResolveFeeOverride::forStudent(...)` hanya untuk pasangan dari langkah 3; selain itu null.
   - Hitung: `ApplyFeeOverride::handle($rate?->amount, $override)`. Null → catat `missing_rate`, lanjut ke jenis biaya berikutnya (santri tetap ditagih jenis biaya lain).
   - `rate_source` sesuai tabel di atas.
6. Kelompokkan hasil per `billing_group` (salinan dari jenis biaya saat itu). **Satu `DB::transaction` per kelompok tagihan** (santri + grup), bukan per santri, sehingga konflik di satu kelompok tidak membatalkan kelompok lain milik santri yang sama:
   - Belum ada tagihan aktif untuk (`student_id`, `period`, `billing_group`) — termasuk bila yang ada hanya tagihan `batal` — dan minimal satu item terhitung → buat `Invoice` (nomor dari `GenerateInvoiceNumber`, `academic_year_id`, `due_on`, `generated_by`, status `belum`), buat itemnya; `total` dihitung di memori dari item yang baru dibuat lalu `syncStatus()` (tanpa query `SUM` per tagihan). Item Rp 0 (bebas biaya) tetap dibuat, tagihan total 0 berstatus `lunas`.
   - Kelompok tanpa satu pun item terhitung → tidak ada tagihan kosong.
   - Sudah ada tagihan aktif (bukan `batal`) → kombinasi itu tidak mendapat tagihan baru:
     - Item yang `fee_type_id`-nya sudah ada **tidak diubah sama sekali** (snapshot), meskipun tarif, keringanan, `billing_group`, atau status mukim berubah.
     - Item yang belum ada ditambahkan hanya bila tagihan `paid_total = 0`, lalu `recalculateTotal()`. Ini menangani tarif yang baru diatur setelah generator pertama atau jenis biaya bulanan baru.
     - Bila `paid_total > 0`, item yang belum ada tidak ditambahkan dan dicatat `invoice_locked`.
     - Jenis biaya yang `billing_group`-nya diubah setelah tagihan dibuat: item lama tetap di tagihan lama; generator mencari tagihan berdasarkan grup baru, dan bila jenis biaya itu sudah ada sebagai item di tagihan **aktif** lain milik santri yang sama untuk periode yang sama, item **tidak** dibuat lagi (cegah tagihan ganda per jenis biaya per bulan).
   - **Konflik unique saat membuat tagihan baru.** `UniqueConstraintViolationException` ditangkap **di luar** closure `DB::transaction` (transaksi kelompok itu sudah di-rollback; aman di Postgres yang membatalkan transaksi setelah error). Penyebabnya dibedakan dengan cek ulang, bukan dari pesan error:
     1. Query ulang `Invoice::withoutUnitScope()` untuk tagihan aktif (`student_id`, `period`, `billing_group`). Ada → proses lain sudah membuatnya: hitung `existing_invoices`, lanjut ke kelompok berikutnya (item susulan untuk tagihan itu menunggu eksekusi berikutnya).
     2. Tidak ada → yang bentrok adalah `invoice_no` (penghitung basi, mis. F010/F011 atau proses lain membuat tagihan berprefiks sama di tengah eksekusi). Muat ulang penghitung dari database (`GenerateInvoiceNumber::lastSequence`) lalu ulangi kelompok itu.
     3. Maksimal **3 percobaan** per kelompok. Bila tetap gagal, lempar `RuntimeException` (pesan `pondok.invoice.errors.number_conflict`) yang menghentikan eksekusi; kelompok yang sudah di-commit tetap ada, lock dilepas, dan eksekusi ulang aman. Santri tidak pernah dilewati diam-diam.
7. Logging:
   - Setiap entri `skipped` → `Log::warning('Tagihan dilewati', [period, student_id, nis, fee_type_id, fee_type_code, reason])` pada channel default.
   - Satu `activity('keuangan')` per eksekusi: `event('generated')`, `causedBy($generatedBy)` (null bila scheduler), deskripsi `pondok.invoice.activity_generated`, properties `period`, `created_invoices`, `added_items`, `existing_invoices`, `skipped_count`.
   - Pembuatan `Invoice` per baris dibungkus `activity()->withoutLogs()`.

`due_on` = tanggal `keuangan.due_day` di bulan period. `Setting::get('keuangan.due_day', 10)`, nilai dibatasi 1–28 (di luar rentang → 10).

Efek samping: insert `invoices`, `invoice_items`, update `invoices.total/status` untuk tagihan yang ditambah item, log aplikasi, satu baris `activity_log`.

### `App\Actions\Keuangan\GenerateInvoiceNumber`
Format `INV-{Ym}-NNNNN` (5 digit nol di depan, mis. `INV-202610-00001`). Method:
- `lastSequence(CarbonInterface $month): int` — nomor urut terbesar berprefiks `INV-{Ym}-`, 0 bila belum ada. Query **wajib** `Invoice::withoutUnitScope()` dan **tanpa filter status** (tagihan `batal` ikut terhitung, karena nomornya tetap terpakai): `->where('invoice_no', 'like', prefix.'%')->max('invoice_no')`, lalu ambil 5 digit terakhir. Lebar tetap sehingga urut string = urut angka.
- `format(CarbonInterface $month, int $sequence): string` — lebih dari 99.999 → `LogicException` (tidak realistis untuk satu sekolah).
- `handle(CarbonInterface $month): string` = `format($month, lastSequence($month) + 1)`, untuk pemakai satu tagihan (F010/F011).

Generator memanggil `lastSequence` sekali per eksekusi, menaikkan penghitung di memori per tagihan baru, dan memuat ulang lewat `lastSequence` saat terjadi konflik nomor (langkah 6). Alasan `withoutUnitScope()`: generator bisa dijalankan dari panel oleh bendahara berjenjang (AC-16); dengan global scope, maksimum hanya dihitung dari tagihan jenjangnya dan nomor bisa bentrok dengan jenjang lain.

### `App\Actions\Keuangan\CancelInvoice`
`handle(Invoice $invoice, User $user, string $reason): Invoice`
- Validasi (`ValidationException`): `reason` wajib, di-`trim`, maks 255 (key `reason`); tagihan sudah `batal` → `pondok.invoice.errors.already_cancelled`; `paid_total > 0` → `pondok.invoice.errors.has_payments` (pembatalan pembayaran adalah F007).
- Efek: dalam satu `DB::transaction`, `status = batal` disimpan di dalam `activity()->withoutLogs(...)` (log otomatis `LogsActivity` tidak ditulis), lalu satu log manual `activity('keuangan')->performedOn($invoice)->causedBy($user)->event('updated')->withProperties(['old' => ['status' => <lama>], 'attributes' => ['status' => 'batal'], 'reason' => $reason])->log(...)`. Tepat satu baris activity per pembatalan. Item, `total`, dan baris tagihan tetap ada.
- Tidak menyentuh keringanan maupun tarif.

### Command `App\Console\Commands\GenerateMonthlyInvoicesCommand`
Signature `keuangan:generate-invoices {--period= : Bulan tagihan YYYY-MM, bawaan bulan berjalan}`.
- Modul `keuangan` mati → pesan info `pondok.invoice.command.module_disabled`, exit `SUCCESS`, tanpa data.
- `--period` tidak berformat `YYYY-MM` → pesan error, exit `FAILURE`.
- `ValidationException` dari Action → pesan error, exit `FAILURE`.
- Sukses → ringkasan (`created_invoices`, `added_items`, `existing_invoices`, jumlah dilewati), exit `SUCCESS`.
- Memanggil Action tanpa user (`generated_by` null), setelah `set_time_limit(0)`.

### Jadwal (`routes/console.php`)
`Schedule::command('keuangan:generate-invoices')->monthlyOn(1, '01:00')->withoutOverlapping()` dengan zona `Asia/Jakarta`. Satu cron `schedule:run` yang sudah ada menjalankannya; tidak ada proses yang berjalan terus.

## UI (Filament / Blade)

Rujukan design guide: §0 checklist, §3 (kapital hanya di awal kalimat), §4 badge tagihan, §5 uang `->money('IDR', locale: 'id', decimalPlaces: 0)` dan periode `translatedFormat('F Y')`, §6 tabel admin, §7 state kosong, §8 konfirmasi generate massal dan batalkan, "Data keuangan tidak punya tombol Hapus". Implementer wajib memakai skill `/anti-ui-slop` bila tersedia.

### `InvoiceResource` (`app/Filament/Resources/Invoices/`)
- `BelongsToModule` (`keuangan`), `ShowsActionErrorsOnForm`, grup `pondok.navigation.keuangan`, `navigationSort = 0` (paling sering dipakai, di atas Jenis biaya), ikon `Heroicon::OutlinedDocumentText`, label `pondok.invoice.label` / `plural`, `$hasTitleCaseModelLabel = false`.
- Halaman: `ListInvoices` (index) dan `ViewInvoice` (detail). Tidak ada Create/Edit/Delete page dan tidak ada `DeleteAction`/`DeleteBulkAction`.
- `getEloquentQuery()`: query bawaan (membawa global scope jenjang `Invoice`).
- Tabel: `defaultSort('period', 'desc')` lalu `invoice_no`; eager-load `student`, `student.unit`. Kolom: `invoice_no` (searchable), `student.nis` (searchable), `student.name` (searchable), `period` (`F Y`), `billing_group`, `total` (money, rata kanan, `tabular-nums`), `paid_total` (toggleable, tersembunyi), `due_on` (`j M Y`, toggleable), `status` (badge dari `statusLabel()`/`statusColor()`). Maks 7 kolom tampil.
- Filter: periode (`SelectFilter` bulan yang ada di tabel, label `F Y`), jenjang (`Unit::accessible()`, lewat `whereHas('student')`), status, kelompok tagihan.
- Empty state: `pondok.invoice.empty` ("Belum ada tagihan untuk periode ini.") dengan aksi "Buat tagihan bulanan" bila user berhak.
- `ViewInvoice` (infolist): nomor, santri (NIS — nama), jenjang, tahun ajaran, periode, kelompok, jatuh tempo, status, total, terbayar, dibuat oleh (`generator.name` atau "Sistem (terjadwal)"); tabel item (deskripsi, tarif normal, potongan, jumlah, sumber tarif, keringanan/alasan; jumlah 0 dengan keringanan nominal ditulis "Gratis").
- Aksi header `ListInvoices`: `InvoiceResource::generateAction()` — label "Buat tagihan bulanan", `primary`, `requiresConfirmation()`, form `Select period` berisi bulan-bulan dalam tahun ajaran aktif (bawaan bulan berjalan), deskripsi modal menjelaskan akibat ("Tagihan dibuat untuk semua santri aktif di semua jenjang. Tagihan yang sudah ada tidak diubah."). Memanggil `GenerateMonthlyInvoices` dengan `auth()->user()`. Sukses → notifikasi `success` "Tagihan Oktober 2026: 120 dibuat, 3 sudah ada, 2 dilewati." Bila ada yang dilewati, notifikasi menyebut "lihat log aplikasi" dan jumlahnya. `ValidationException` → error pada field `period` (modal tetap terbuka). Otorisasi lewat policy `generate`.
- Aksi baris (di `ActionGroup`, juga di header `ViewInvoice`): `InvoiceResource::cancelAction()` — label "Batalkan tagihan", `danger`, `requiresConfirmation()`, deskripsi "Tagihan yang dibatalkan tidak bisa dibayar lagi. Data tetap tersimpan. Tagihan baru untuk periode ini akan dibuat saat generator dijalankan lagi.", field `reason` wajib. Tersembunyi bila `! isCancellable()`. Otorisasi lewat policy `cancel`.

### Relasi di halaman lain
Tidak ada perubahan UI pada `StudentResource` (riwayat tagihan per santri bisa menjadi relation manager di fitur portal/laporan).

### `lang/id/pondok.php` — bagian `invoice`
`label`, `plural`, kolom (`invoice_no`, `nis`, `student_name`, `period`, `billing_group`, `total`, `paid_total`, `due_on`, `status`, `unit`, `academic_year`, `generated_by`, `generated_by_system`), `statuses.{belum,sebagian,lunas,batal}` ("Belum bayar", "Sebagian", "Lunas", "Batal"), `overdue` ("Lewat jatuh tempo"), item (`description`, `base_amount`, `discount_amount`, `amount`, `rate_source`, `rate_sources.{angkatan,jenjang,manual}`, `fee_override`, `free`), `generate_action`, `generate_description`, `generate_period`, `generated` (dengan placeholder `:period`, `:created`, `:existing`, `:skipped`), `cancel_action`, `cancel_description`, `cancel_reason`, `cancelled`, `empty`, `activity_generated`, `errors.{no_academic_year, already_running, already_cancelled, has_payments, number_conflict}`, `command.{module_disabled, invalid_period, summary}`, `item_description` (`:fee_type :period`).

## Permission & cakupan

- `RolePermissionSeeder::RESOURCE_MODELS` ditambah `'Invoice'`; `EXTRA_RESOURCE_ACTIONS['Invoice'] = ['generate', 'cancel']` → permission `generate:invoice`, `cancel:invoice` (format Shield, kebab, pemisah `:`).
- `InvoicePolicy` (pola `FeeOverridePolicy`): `viewAny`, `view`, `create`, `update`, `delete`, `deleteAny` memeriksa permission standar; tambah `generate(AuthUser)` → `generate:invoice` dan `cancel(AuthUser, Invoice)` → `cancel:invoice`. `InvoiceItem` tidak punya resource maupun policy.
- `bendahara` mendapat: `view-any:invoice`, `view:invoice`, `generate:invoice`, `cancel:invoice`. **Tidak** mendapat `create`/`update`/`delete`/`delete-any` (tagihan hanya dibuat generator dan dibatalkan, tidak dihapus).
- `super-admin` otomatis semua. Peran lain tidak mendapat permission tagihan di F006 (`admin-sekolah` dkk. tetap 403).
- Dataset `bendahara` di `RolePermissionSeederTest` ditambah keempat permission di atas; dataset peran lain tidak berubah.
- Cakupan jenjang: daftar dan detail tagihan di panel memakai global scope `Invoice` (lewat santri). Generator dan command tidak memakai cakupan jenjang.
- **Keputusan sadar (AC-16):** bendahara berjenjang yang menekan "Buat tagihan bulanan" membuat tagihan untuk **semua** jenjang, dan notifikasi menampilkan jumlah lintas jenjang. Generator adalah operasi tingkat sekolah (sama dengan jadwal tanggal 1) dan idempotent, jadi tidak ada data ganda. Teks modal menyebut "semua santri aktif di semua jenjang". Membatasi generator per jenjang tidak termasuk F006.
- Saklar `modules.keuangan.enabled`: resource lewat `BelongsToModule` (menu hilang, 403); command keluar tanpa membuat data; jadwal tetap terdaftar tetapi command tidak berbuat apa-apa.

## Kriteria penerimaan (bisa dites)

Fixture umum: tahun ajaran 2026/2027 (`starts_on` 2026-07-01, `ends_on` 2027-06-30), jenjang SLTP, angkatan 2026, asrama A; jenis biaya bulanan aktif SPP (`semua`, grup `BULANAN`, prioritas 3), ASRAMA (`mukim`, grup `BULANAN`, prioritas 2), MAKAN (`mukim`, grup `BULANAN`, prioritas 1); tarif jenjang SPP 500.000, ASRAMA 300.000, MAKAN 400.000; periode Oktober 2026.

**Model data & constraint**
- AC-1: Lewat model/factory, dua `invoices` **aktif** (status bukan `batal`) dengan `student_id`, `period`, `billing_group` sama → insert kedua melempar `UniqueConstraintViolationException`. Beda `billing_group` atau beda `period` → boleh. Menyetel tagihan `batal` kembali ke `belum` ketika sudah ada tagihan aktif untuk kombinasi itu → `UniqueConstraintViolationException`.
- AC-2: Dua `invoices` aktif dengan `applicant_id` dan `billing_group` sama (tanpa `student_id`) → `UniqueConstraintViolationException`. Dua tagihan santri dengan `applicant_id` NULL dan grup sama tetapi periode berbeda → boleh (NULL tidak bentrok).
- AC-3: Menyimpan `Invoice` dengan `student_id` dan `applicant_id` sama-sama null → `LogicException`; tidak ada baris tersimpan.
- AC-4: Dua `invoice_items` dengan `invoice_id` dan `fee_type_id` sama → `UniqueConstraintViolationException`.
- AC-5: Menghapus `invoices` yang punya item, `fee_types`/`fee_overrides`/`academic_years`/`students` (force delete)/`users` (`generated_by`) yang dirujuk tagihan atau item → `QueryException` (FK restrict). Soft delete santri tetap berhasil dan tagihannya tetap ada.
- AC-6: Kolom uang `invoices.total`, `paid_total`, `invoice_items.base_amount`, `discount_amount`, `amount`, `paid_amount` bertipe integer (bukan decimal/float) dan `paid_total`/`paid_amount` bawaan 0; `status` bawaan `belum`.

**Generator — dasar dan gabungan/terpisah**
- AC-7 (gabungan): santri mukim, ketiga jenis biaya grup `BULANAN` → tepat 1 tagihan berisi 3 item; `total` 1.200.000; `status` `belum`; `period` 2026-10-01; `academic_year_id` 2026/2027; `due_on` 2026-10-10 (bawaan); `generated_by` null bila tanpa user; hasil `created_invoices` 1.
- AC-8 (terpisah): SPP grup `SPP`, ASRAMA dan MAKAN grup `ASRAMA` → 2 tagihan untuk santri mukim: `SPP` (1 item, 500.000) dan `ASRAMA` (2 item, 700.000). Grup berbeda untuk ketiganya → 3 tagihan.
- AC-9: Item menyimpan `description` "SPP Oktober 2026", `base_amount`, `discount_amount` 0, `amount` sama dengan tarif, `rate_source` `jenjang`; tarif angkatan → `rate_source` `angkatan`. `fee_override_id` null tanpa keringanan.
- AC-10: Tarif dipilih lewat `ResolveFeeRate` dengan tahun ajaran yang memuat periode dan `dormitory_id` kamar aktif: tarif asrama A (jenjang + asrama) 350.000 menang atas tarif jenjang 300.000 untuk santri di asrama A; santri di asrama B mendapat 300.000.
- AC-11: Nomor tagihan berformat `INV-202610-00001`, `INV-202610-00002`, … berurutan dan unik; bila sudah ada `INV-202610-00007`, tagihan berikutnya `INV-202610-00008`. Periode November memakai prefiks `INV-202611-` dan mulai `00001`.

**Generator — mukim/nonmukim dan sasaran santri**
- AC-12: Santri nonmukim (tanpa `room_assignments` aktif, atau hanya penempatan yang sudah `end_on`) → tidak mendapat item ASRAMA dan MAKAN; hanya SPP.
- AC-13: Jenis biaya `applies_to = nonmukim` (mis. TRANSPORT) hanya ditagihkan ke santri nonmukim; `semua` ke keduanya.
- AC-14: Santri berstatus `lulus`/`keluar`/`pindah`, santri soft-deleted, dan santri dengan `entered_on` setelah 2026-10-31 tidak mendapat tagihan. Santri dengan `entered_on` 2026-10-20 mendapat tagihan Oktober.
- AC-15: Jenis biaya nonaktif, atau frekuensi selain `bulanan` (`tahunan`, `saat_daftar`, `saat_diterima`, `manual`), tidak pernah menjadi item.
- AC-16: Generator dijalankan saat login sebagai user berjenjang SLTP saja → santri SLTA juga mendapat tagihan (cakupan jenjang tidak menyaring generator).

**Generator — keringanan**
- AC-17: Keringanan aktif di Oktober diterapkan lewat `ApplyFeeOverride`: mode `potongan` 100.000 pada SPP → base 500.000, discount 100.000, amount 400.000, `fee_override_id` terisi; mode `persen` 50 → amount 250.000; mode `nominal` 0 → amount 0, discount 500.000 (item Rp 0 tetap dibuat).
- AC-18: Keringanan yang berakhir 2026-09-30 atau mulai 2026-11-01 tidak diterapkan pada tagihan Oktober; keringanan yang berakhir 2026-10-05 diterapkan (irisan bulan, kontrak F005).
- AC-19: Santri yang semua itemnya bebas biaya → tagihan total 0 berstatus `lunas`.

**Generator — tanpa tarif**
- AC-20: Jenis biaya ASRAMA tanpa tarif untuk jenjang santri (mukim) → item ASRAMA dilewati, item SPP dan MAKAN tetap dibuat; hasil `skipped` memuat `{student_id, fee_type_id: ASRAMA, reason: 'missing_rate'}`; `Log::warning` dipanggil dengan konteks berisi `student_id`, `fee_type_id`, `period`, `reason`.
- AC-21: Santri tanpa tarif untuk semua jenis biaya → tidak ada tagihan sama sekali (tidak ada tagihan kosong), tercatat di `skipped` dan log; santri lain dalam eksekusi yang sama tetap ditagih.
- AC-22: Tanpa tarif tetapi ada keringanan `nominal` 250.000 → item dibuat dengan base 250.000, discount 0, amount 250.000, `rate_source` `manual`. Tanpa tarif dengan keringanan `potongan`/`persen` → dilewati seperti AC-20.

**Generator — idempotent dan snapshot**
- AC-23: Menjalankan generator dua kali untuk periode sama → jumlah `invoices` dan `invoice_items` tidak bertambah; eksekusi kedua `created_invoices` 0, `added_items` 0, `existing_invoices` sama dengan jumlah tagihan; nomor tagihan tidak berubah.
- AC-24 (snapshot tarif): setelah generator Oktober, tarif SPP diubah ke 600.000 (edit atau baris tahun baru) lalu generator dijalankan ulang untuk Oktober → item SPP tetap 500.000 dan `total` tidak berubah. Generator November memakai 600.000.
- AC-25 (snapshot keringanan/mukim): setelah generator, keringanan baru ditambahkan atau santri dipindah keluar asrama, lalu dijalankan ulang → item yang sudah ada tidak berubah dan item ASRAMA/MAKAN tidak dihapus.
- AC-26 (item susulan): Oktober dibuat saat ASRAMA belum punya tarif (dilewati); tarif ASRAMA lalu diatur dan generator dijalankan ulang → item ASRAMA ditambahkan ke tagihan `BULANAN` yang sama, `total` dihitung ulang (500.000 + 400.000 + 300.000), hasil `added_items` 1, tidak ada tagihan baru.
- AC-27 (tagihan terkunci): bila tagihan aktif yang sudah ada `paid_total > 0`, item yang belum ada tidak ditambahkan, total dan status tidak berubah, dan `skipped` memuat `reason: 'invoice_locked'`.
- AC-28: Jenis biaya SPP sudah menjadi item di tagihan aktif `BULANAN` Oktober; `billing_group` SPP diubah ke `SPP` lalu generator dijalankan ulang → tidak ada tagihan `SPP` baru berisi SPP (satu jenis biaya hanya sekali per santri per periode).

**Generator — prasyarat, kinerja, audit**
- AC-29: Periode tanpa tahun ajaran yang memuatnya (mis. 2030-01) → `ValidationException` pada `period` dengan pesan `pondok.invoice.errors.no_academic_year`; tidak ada tagihan dibuat.
- AC-30: Lock periode sedang dipegang (`Cache::lock('keuangan:generate-invoices:2026-10')` diambil di test) → `ValidationException` `already_running`; setelah dilepas, generator berjalan normal. Lock dilepas setelah eksekusi selesai maupun gagal.
- AC-31: Periode diberikan sebagai 2026-10-17 → tagihan ber-`period` 2026-10-01.
- AC-32 (memo tarif): 10 santri dengan jenjang, angkatan, dan asrama sama, 3 jenis biaya → `ResolveFeeRate::handle` dipanggil tepat 3 kali (spy di container). `ResolveFeeOverride::forStudent` hanya dipanggil untuk pasangan santri–jenis biaya yang punya keringanan beririsan bulan itu.
- AC-33 (tanpa N+1): fixture setara tanpa keringanan, semua santri dalam satu chunk, semua tagihan baru. Jumlah query `SELECT` (dari `DB::enableQueryLog`) untuk 20 santri **sama persis** dengan untuk 10 santri. Query `activeRoom`, tarif, keringanan, tagihan lama, dan nomor tagihan tidak boleh muncul per santri; hanya `INSERT`/`UPDATE` dan transaksi yang bertambah.
- AC-34: Satu eksekusi generator menghasilkan tepat satu activity log `keuangan` event `generated` dengan properties `period`, `created_invoices`, `added_items`, `existing_invoices`, `skipped_count`; causer = user bila dari panel, null bila tanpa user. Tidak ada activity `created` per tagihan.
- AC-35: Eksekusi dari panel mengisi `generated_by` dengan user yang menjalankan.

**Pembatalan**
- AC-36: `CancelInvoice` pada tagihan `belum` → status `batal`, item dan total tetap, baris tidak terhapus; **tepat satu** baris activity untuk tagihan itu dari pembatalan: log `keuangan`, event `updated`, `old.status` `belum`, `attributes.status` `batal`, properti `reason` (sudah di-`trim`), causer user.
- AC-37: `CancelInvoice` pada tagihan sudah `batal` → `ValidationException` `already_cancelled`; pada tagihan `paid_total > 0` → `has_payments`; `reason` kosong/spasi → error pada `reason`. Status tidak berubah.
- AC-38: `Invoice::syncStatus()`: (total 500.000, paid 0) → `belum`; (500.000, 200.000) → `sebagian`; (500.000, 500.000) → `lunas`; (0, 0) → `lunas`; status `batal` tidak pernah berubah.

**Command & scheduler**
- AC-39: `php artisan keuangan:generate-invoices --period=2026-10` → exit 0, tagihan Oktober dibuat, output memuat ringkasan; `generated_by` null.
- AC-40: Tanpa `--period` → memakai bulan berjalan (`Carbon::setTestNow('2026-10-01 01:00', 'Asia/Jakarta')` → tagihan Oktober 2026).
- AC-41: `--period=2026-13` atau `--period=oktober` → exit 1, tanpa data. Periode tanpa tahun ajaran → exit 1.
- AC-42: Modul `keuangan` mati → exit 0, pesan `module_disabled`, tidak ada tagihan dibuat.
- AC-43: Jadwal terdaftar: daftar event `Schedule` memuat command `keuangan:generate-invoices` dengan ekspresi cron `0 1 1 * *`, zona `Asia/Jakarta`, `withoutOverlapping`. Jadwal `queue:work` yang lama tetap ada.

**Panel & otorisasi**
- AC-44: `bendahara` dan `super-admin` bisa membuka `/admin/invoices` dan `/admin/invoices/{id}` (200). `admin-sekolah`, `kesantrian`, `musyrif`, `admin-konten`, `admin-psb`, `ustadz-tahfidz`, `petugas-pos` → 403.
- AC-45: Modul `keuangan` mati → `/admin/invoices` 403 dan menu tidak tampil.
- AC-46: User `bendahara` berjenjang SLTP hanya melihat tagihan santri SLTP di daftar; membuka detail tagihan santri SLTA → 404. Tagihan santri SLTP yang di-soft-delete tetap terlihat. Bendahara tanpa `unit_user` melihat semua.
- AC-47: Aksi "Buat tagihan bulanan" (Livewire `callAction`) dengan periode Oktober 2026 oleh `bendahara` → tagihan dibuat, notifikasi `success` menyebut periode dan jumlah; dijalankan ulang → tidak ada tagihan baru. Periode tanpa tahun ajaran → error pada field `period`, tanpa data.
- AC-48: Aksi "Buat tagihan bulanan" tidak terlihat/tidak bisa dijalankan oleh user dengan `view-any:invoice` tetapi tanpa `generate:invoice`.
- AC-49: Aksi "Batalkan tagihan" dengan alasan → status `batal`; tidak tampil untuk tagihan `batal` atau `paid_total > 0`; tidak bisa dijalankan tanpa `cancel:invoice`. Tidak ada aksi Hapus/Edit di daftar maupun detail.
- AC-50: Daftar menampilkan uang `Rp 1.200.000`, periode `Oktober 2026`, badge status dengan label `lang/id` ("Belum bayar"); tagihan `belum` dengan `due_on` lewat menampilkan "Lewat jatuh tempo". Daftar kosong menampilkan `pondok.invoice.empty`.
- AC-51: Detail tagihan menampilkan semua item dengan tarif normal, potongan, dan jumlah; item Rp 0 dari keringanan nominal 0 tertulis "Gratis".
- AC-52: `RolePermissionSeeder` membuat `generate:invoice` dan `cancel:invoice` beserta permission resource `Invoice`; dataset `bendahara` di `RolePermissionSeederTest` persis bertambah `view-any:invoice`, `view:invoice`, `generate:invoice`, `cancel:invoice`; dataset peran lain tidak berubah; test "every permission that a generated policy checks" tetap lulus dengan `InvoicePolicy`.
- AC-53: `EditUser` menyembunyikan tombol Hapus untuk user yang tercatat sebagai `generated_by` tagihan.
- AC-54: Semua teks baru ada di `lang/id/pondok.php` (tidak ada key mentah `pondok.invoice.*` tampil di halaman daftar, detail, dan modal).

**Tagihan batal dan penerbitan ulang (keputusan orkestrator, r2)**
- AC-55: Tagihan `BULANAN` Oktober santri X dibatalkan (`CancelInvoice`), lalu generator Oktober dijalankan ulang → dibuat satu tagihan **baru** `BULANAN` Oktober untuk X dengan `invoice_no` baru (nomor urut berikutnya, berbeda dari yang dibatalkan), status `belum`, item dihitung ulang dari tarif dan keringanan saat ini (mis. tarif SPP sudah dikoreksi 450.000 → item SPP 450.000). Tagihan lama tetap ada dengan status `batal`, item dan total tidak berubah. Hasil `created_invoices` 1. Generator dijalankan sekali lagi → tidak ada tagihan baru (`existing_invoices` 1).
- AC-56: Lewat model/factory (`period` = `startOfMonth()`, bukan `DB::table`), dua tagihan aktif dengan `student_id`, `period`, `billing_group` sama → insert kedua `UniqueConstraintViolationException`; satu tagihan `batal` dan satu tagihan aktif dengan kombinasi sama → boleh. Hal yang sama untuk (`applicant_id`, `billing_group`).
- AC-57: Lewat model/factory, tiga tagihan `batal` dengan `student_id`, `period`, `billing_group` sama → semuanya tersimpan (marker NULL tidak bentrok); `active_marker` bernilai NULL untuk ketiganya dan 1 untuk tagihan `belum`/`sebagian`/`lunas`. Berlaku di SQLite (dites); migration memakai `storedAs` yang juga berjalan di MySQL/MariaDB dan Postgres.
- AC-58: Generator mengabaikan item tagihan `batal`: santri yang tagihan `BULANAN`-nya dibatalkan lalu `billing_group` SPP diubah ke `SPP` → generator ulang membuat tagihan `SPP` berisi SPP dan tagihan `BULANAN` baru berisi ASRAMA/MAKAN (pengecekan "satu jenis biaya sekali per periode" di AC-28 hanya melihat tagihan aktif).

**Nomor tagihan dan konflik (r3, Review #1)**
- AC-59: Sudah ada tagihan santri SLTA `INV-202610-00005` (aktif) dan `INV-202610-00006` (`batal`). Generator Oktober dijalankan saat login sebagai bendahara berjenjang SLTP saja → tagihan baru santri SLTP bernomor `INV-202610-00007` dan seterusnya; tidak ada `UniqueConstraintViolationException`, semua nomor unik. `GenerateInvoiceNumber::lastSequence` mengembalikan 6 untuk user itu.
- AC-60 (penghitung basi): `GenerateInvoiceNumber` di container diganti partial mock yang `lastSequence` pertama kali mengembalikan 0 lalu memanggil implementasi asli; sudah ada `INV-202610-00001` milik santri lain (kombinasi berbeda). Generator untuk santri X → X tetap mendapat tagihan aktif bernomor baru (`INV-202610-00002`), `created_invoices` 1, `existing_invoices` 0, dan `lastSequence` dipanggil 2 kali.
- AC-61 (batas percobaan): partial mock yang `lastSequence` selalu mengembalikan 0 sementara `INV-202610-00001` sudah ada → generator melempar `RuntimeException` (`number_conflict`) setelah tepat 3 percobaan untuk kelompok itu; tidak ada tagihan setengah jadi (tanpa item yatim); lock periode sudah dilepas (lock bisa diambil lagi).
- AC-62 (konflik per kelompok): santri mukim dengan grup `SPP` dan `ASRAMA`; tagihan aktif `ASRAMA` Oktober untuknya disisipkan oleh "proses lain" setelah preload (disimulasikan dengan partial mock/hook yang membuat tagihan itu tepat sebelum insert kelompok `ASRAMA`) → tagihan `SPP` tetap dibuat dan di-commit, kelompok `ASRAMA` dihitung `existing_invoices` 1 tanpa tagihan ganda, `created_invoices` 1.

## Rencana test

| AC | File | Nama test (usulan) |
|---|---|---|
| 1–6, 56–57 | `tests/Feature/Database/KeuanganConstraintsTest.php` (tambah) | `it('AC-1: rejects a second invoice for the same student, period and group')`, `AC-2 … applicant and group`, `AC-3 … invoice without owner`, `AC-4 … duplicate fee type per invoice`, `AC-5 … restricts deleting referenced finance rows`, `AC-6 … stores money as integers with zero defaults`, `AC-56: allows one active invoice beside cancelled ones`, `AC-57: allows many cancelled invoices for one combination` |
| 7–11 | `tests/Feature/Actions/Keuangan/GenerateMonthlyInvoicesTest.php` | `AC-7: combines fee types of one billing group into one invoice`, `AC-8: splits invoices per billing group` (dataset 2 grup / 3 grup), `AC-9: snapshots item amounts and rate source`, `AC-10: resolves the dormitory rate from the active room`, `AC-11: numbers invoices per period` |
| 12–16 | sama | `AC-12: does not bill resident fees to non-residents`, `AC-13: bills nonmukim fees only to non-residents`, `AC-14: bills only active students who entered by the period`, `AC-15: ignores inactive and non-monthly fee types`, `AC-16: ignores the unit scope of the logged-in user` |
| 17–19 | sama | `AC-17: applies the three override modes` (dataset), `AC-18: applies overrides that overlap the billing month`, `AC-19: marks a fully free invoice as paid` |
| 20–22 | sama | `AC-20: skips and logs a fee type without a rate` (`Log::spy()`), `AC-21: creates no empty invoice when nothing resolves`, `AC-22: bills a nominal override without a rate as manual` |
| 23–28, 55, 58–62 | `tests/Feature/Actions/Keuangan/GenerateMonthlyInvoicesRerunTest.php` | `AC-23: is idempotent`, `AC-24: keeps item snapshots after a rate change`, `AC-25: keeps items after override or residency changes`, `AC-26: adds a newly resolvable item to an unpaid invoice`, `AC-27: leaves cancelled or paid invoices untouched`, `AC-28: bills a fee type once per period after its group changes`, `AC-55: reissues a cancelled invoice with a new number`, `AC-58: ignores cancelled invoices when checking billed fee types`, `AC-59: numbers invoices across units for a scoped user`, `AC-60: retries with a fresh counter on a number clash`, `AC-61: stops after three number clashes`, `AC-62: isolates a conflict to one billing group` |
| 29–35 | `GenerateMonthlyInvoicesTest.php` | `AC-29: rejects a period outside any academic year`, `AC-30: refuses to run twice for the same period`, `AC-31: normalises the period to the first day`, `AC-32: memoises rate resolution`, `AC-33: keeps select queries flat per student` (`DB::enableQueryLog`), `AC-34: logs one summary activity per run`, `AC-35: records the generating user` |
| 36–38 | `tests/Feature/Actions/Keuangan/CancelInvoiceTest.php`, `tests/Feature/Models/InvoiceTest.php` | `AC-36: cancels an unpaid invoice with exactly one activity row`, `AC-37: rejects cancelling cancelled or paid invoices` (dataset), `AC-38: syncs status from paid and total` (dataset) |
| 39–43 | `tests/Feature/Console/GenerateMonthlyInvoicesCommandTest.php` | `AC-39: generates invoices for the given period`, `AC-40: defaults to the current month`, `AC-41: fails on an invalid period` (dataset), `AC-42: does nothing when the module is disabled`, `AC-43: schedules the generator on the first of the month` (`app(Schedule::class)->events()`) |
| 44–45 | `tests/Feature/Filament/KeuanganAccessTest.php` (tambah) | `AC-44: lets bendahara open invoices`, `AC-44: forbids invoices to other roles` (dataset peran), `AC-45: hides invoices when the module is disabled` |
| 46–51, 53–54 | `tests/Feature/Filament/Resources/Invoices/InvoiceResourceTest.php` | `AC-46: scopes invoices to the user's units`, `AC-47: generates invoices from the panel`, `AC-48: hides generate without permission`, `AC-49: cancels from the panel without delete actions`, `AC-50: formats money, period and status`, `AC-51: shows invoice items with free label`, `AC-54: has no raw lang keys`; AC-53 di `tests/Feature/Filament/Resources/Users/…` (file EditUser yang ada) `hides delete for a user who generated invoices` |
| 52 | `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` | ubah dataset `bendahara`; test yang ada mencakup sisanya |

Helper fixture baru di `tests/Pest.php` mengikuti pola `keuanganFixture()`/`keringananFixture()`: `tagihanFixture()` (tahun ajaran, jenjang, angkatan, asrama, tiga jenis biaya, tarif) dan `tagihanResident(Student)`.

## Urutan implementasi

1. Migration + model + factory `invoices` (generated column `active_marker`, unique dengan marker, index, guard pemilik, `syncStatus`, `recalculateTotal`, scope jenjang, activitylog status) — AC-1–3, 5–6, 38, 56–57.
2. Migration + model + factory `invoice_items` + relasi baru di model lama — AC-4–6.
3. `GenerateInvoiceNumber` (`lastSequence` tanpa scope jenjang dan tanpa filter status, `format`, `handle`) — AC-11, 59.
4. `GenerateMonthlyInvoices` jalur dasar (gabungan/terpisah, mukim/nonmukim, sasaran santri, tanpa tarif + log) — AC-7–10, 12–22, 29, 31.
5. Rerun dan snapshot (tagihan lama, item susulan, terkunci, pindah grup, penerbitan ulang setelah batal) — AC-23–28, 55, 58.
6. Transaksi per kelompok + penanganan konflik unique dengan cek ulang dan maks. 3 percobaan — AC-60–62.
7. Lock, memo tarif, preload per chunk, ringkasan activity, `generated_by` — AC-30, 32–35.
8. `CancelInvoice` — AC-36–37.
9. Command + jadwal `routes/console.php` — AC-39–43.
10. Permission seeder + `InvoicePolicy` + dataset seeder test — AC-52.
11. `InvoiceResource` daftar + detail + cakupan jenjang + teks `lang/id` — AC-44–46, 50–51, 54.
12. Aksi panel generate dan batalkan — AC-47–49.
13. `EditUser` guard `generated_by` — AC-53.
14. Pint, checklist design guide §0, cek visual (atau laporkan belum dicek).

## Risiko & pertanyaan terbuka

Setiap butir punya default yang dipakai bila user tidak memutuskan lain.

1. **Tagihan `batal` dan penerbitan ulang.** *Diputuskan orkestrator (r2), bukan default:* `invoices` memakai generated column `active_marker` dengan unique (`student_id`, `period`, `billing_group`, `active_marker`) dan (`applicant_id`, `billing_group`, `active_marker`). Pembatalan membuka jalan koreksi: generator berikutnya (terjadwal, command, atau tombol panel) membuat tagihan baru bernomor baru. Konsekuensi yang disadari: pembatalan **tidak** berarti "jangan tagih bulan ini"; bila sekolah ingin membebaskan santri untuk satu bulan, pakai keringanan nominal 0 (F005), bukan pembatalan. Teks modal pembatalan menyebut ini ("Tagihan baru untuk periode ini akan dibuat saat generator dijalankan lagi.").
2. **Item susulan pada rerun.** *Default:* item yang belum ada ditambahkan ke tagihan lama hanya bila belum ada pembayaran dan tidak `batal` (mengikuti pseudocode ERD `firstOrCreate`); item lama tidak pernah diubah. Alternatif yang ditolak: tidak pernah menambah item (tarif yang terlambat diatur tidak akan pernah tertagih bulan itu).
3. **Status mukim** dibaca saat generator berjalan (tanggal 1 biasanya), bukan dihitung pro-rata. *Default:* tanpa pro-rata; santri yang masuk asrama tanggal 15 baru ditagih biaya asrama bulan berikutnya, kecuali bendahara menjalankan generator ulang bulan itu (item susulan menambah ASRAMA/MAKAN bila tagihan belum dibayar). Konsisten dengan F005 (keringanan tanpa pro-rata).
4. **Aksi panel sinkron.** Ratusan santri × beberapa jenis biaya diperkirakan beberapa detik dengan memo tarif dan preload. *Default:* sinkron, tanpa queue. Command memanggil `set_time_limit(0)` (CLI); aksi panel tidak mengubah batas waktu dan tunduk pada `max_execution_time` hosting (umumnya 30–60 detik). Bila request terputus, kelompok yang sudah di-commit tetap ada, lock kedaluwarsa setelah 30 menit, dan menjalankan ulang (tombol atau command) melanjutkan sisanya karena idempotent. Batas yang dianggap aman untuk aksi panel: sekitar 1.000 santri aktif; di atas itu, pakai command/jadwal atau pindahkan ke job queue `database` (fitur terpisah).
5. **Jam jadwal.** *Default:* tanggal 1 pukul 01:00 WIB, `withoutOverlapping`. Bila cron hosting mati saat itu, bendahara memakai tombol panel. Alternatif (menjalankan harian untuk bulan berjalan) ditolak karena akan otomatis menagih santri baru di tengah bulan, di luar kesepakatan "tanggal 1".
6. **Jatuh tempo.** *Default:* tanggal 10 bulan tagihan, bisa diubah lewat `settings` key `keuangan.due_day` (1–28) tanpa UI di F006.
7. **Santri baru di tengah bulan** (`entered_on` dalam bulan periode) ikut ditagih penuh bila generator dijalankan setelah ia aktif. *Default:* ya, tanpa pro-rata.
8. **`rate_source = manual`** dipakai untuk item tanpa tarif yang nominalnya dari keringanan nominal. ERD hanya menyebut nilai enum tanpa arti; *default* ini didokumentasikan di docblock `InvoiceItem`.
9. **Log "santri tanpa tarif".** *Default:* `Log::warning` per santri–jenis biaya di log aplikasi + daftar `skipped` di hasil + jumlah di notifikasi panel/command + `skipped_count` di activity ringkasan. Tidak ada tabel log baru. Halaman "tagihan dilewati" di panel bisa diusulkan terpisah bila dibutuhkan.
10. **Check constraint pemilik** dijaga di model, bukan database (lihat Model data). Insert lewat query builder mentah bisa melanggarnya; semua jalur aplikasi lewat model.
11. **Periode di panel** dibatasi ke bulan dalam tahun ajaran aktif; Action menerima periode apa pun yang punya tahun ajaran (command bisa mengisi bulan lampau). *Default:* tidak ada larangan periode lampau/masa depan selain tahun ajaran harus ada.
12. **Tagihan pendaftar untuk user berjenjang** tidak terlihat sampai F010 memperluas `applyUnitScope` lewat `applicants.unit_id`. Dicatat untuk F010.
13. **Grup tagihan PSB vs bulanan** (untuk F012): setelah konversi, tagihan PSB mendapat `student_id`; bila `billing_group` dan `period`-nya sama dengan tagihan bulanan, unique bentrok. Dicatat untuk F010/F012 (grup PSB sebaiknya berbeda, mis. `PSB`).

## Riwayat revisi

- r1 (DRAFT): versi awal oleh product-manager.
- r2 (DRAFT): keputusan orkestrator atas pertanyaan terbuka #1 (default r1 ditolak; default #2–#13 diterima).
  - `invoices` mendapat generated column stored `active_marker = CASE WHEN status <> 'batal' THEN 1 END`; unique diganti menjadi (`student_id`, `period`, `billing_group`, `active_marker`) dan (`applicant_id`, `billing_group`, `active_marker`).
  - Generator hanya menganggap tagihan non-batal sebagai "sudah ada"; kombinasi yang hanya punya tagihan `batal` mendapat tagihan baru bernomor baru. Alasan `invoice_locked` kini hanya untuk tagihan aktif yang sudah dibayar.
  - AC-1, AC-2, AC-27, AC-28 disesuaikan; AC-55–AC-58 ditambahkan (jumlah AC 58). Rencana test, urutan implementasi, Risiko #1, dan teks modal pembatalan diperbarui. Usulan entri baru "Terbitkan ulang tagihan batal" dari r1 dibatalkan karena sudah masuk F006.
  - **Perubahan ERD atas keputusan orkestrator:** baris constraint `invoices` di `docs/erd.md` diperbarui (unique dengan `active_marker`).
- r3 (DRAFT): menanggapi Review #1 (CHANGES_REQUESTED).
  - Major #1: `GenerateInvoiceNumber` dipecah menjadi `lastSequence`/`format`/`handle`; `lastSequence` wajib `Invoice::withoutUnitScope()` tanpa filter status. AC-59 (bendahara berjenjang, nomor tertinggi milik jenjang lain dan tagihan `batal`).
  - Major #2: konflik unique dibedakan dengan cek ulang tagihan aktif kombinasi itu; bila tidak ada, penghitung dimuat ulang dan dicoba lagi, maksimal 3 kali, lalu `RuntimeException` `number_conflict`. AC-60, AC-61.
  - Minor #3: transaksi per kelompok tagihan (santri + grup), bukan per santri. AC-62.
  - Minor #4: TTL lock 1800 detik, docblock "eksekusi terputus aman diulang", `set_time_limit(0)` di command, batas aksi panel di Risiko #4.
  - Minor #5: satu jalur log pembatalan (`withoutLogs` + satu log manual dengan `reason`); AC-36 menegaskan tepat satu baris.
  - Minor #6: komentar migration untuk penyimpangan sadar dari pola `COALESCE`; kolom `active_marker` ditambahkan ke entitas `invoices` di diagram Mermaid `docs/erd.md`.
  - Minor #7: test constraint (AC-1, AC-56, AC-57) lewat model/factory dengan `period` `startOfMonth()`.
  - Minor #8: dependensi `GenerateMonthlyInvoices` lewat konstruktor, Action di-resolve dari container.
  - Minor #9: AC-16 didokumentasikan sebagai keputusan sadar di bagian Permission & cakupan; teks modal menyebut "semua jenjang".
  - Jumlah AC menjadi 62. Catatan reviewer tidak dihapus.

## Review #1 — 2026-10-15

Verdict: CHANGES_REQUESTED

Cakupan yang sudah baik: semua `acceptance` F006 dan PRD 3.5 terpetakan; kontrak `ResolveFeeRate`/`ResolveFeeOverride`/`ApplyFeeOverride` dipakai sesuai kode (override hanya dipilih lewat `forStudent`, `ApplyFeeOverride` mengembalikan null untuk `missing_rate`); `active_marker` mengikuti pola `room_assignments` dan portabel (NULL tidak bentrok di SQLite/MySQL/Postgres); scheduler tanpa proses berjalan terus, lock memakai `cache_locks` yang sudah ada, timezone app sudah `Asia/Jakarta`; `restrictOnDelete`, tanpa Delete, uang integer, snapshot, urutan implementasi dan pemetaan AC-ke-test sudah konsisten.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | `GenerateInvoiceNumber` + `Invoice::applyUnitScope` (CLAUDE.md "Otorisasi", `app/Models/Scopes/UnitScope.php`) | `GenerateInvoiceNumber` mengambil `max(invoice_no)` lewat model `Invoice` yang punya global scope jenjang. Plan hanya menyebut `withoutUnitScope()` untuk query tagihan lama di generator, bukan untuk nomor. Bila generator dijalankan dari panel oleh bendahara berjenjang (AC-16 sengaja mengizinkan ini), maksimum dihitung dari tagihan jenjangnya saja, sehingga nomor bisa bentrok dengan tagihan jenjang lain. AC-16 dan AC-11 tidak akan menangkapnya kecuali fixture sengaja menaruh nomor tertinggi di jenjang lain. | Wajibkan `Invoice::withoutUnitScope()` (dan tanpa filter status, agar tagihan `batal` ikut terhitung) di `GenerateInvoiceNumber`. Tambahkan AC: user berjenjang SLTP menjalankan generator saat nomor tertinggi bulan itu milik santri SLTA, nomor baru tetap unik dan lebih besar. |
| 2 | major | Algoritma langkah 6 (tangkap `UniqueConstraintViolationException`), `GenerateInvoiceNumber` | Catch tunggal menganggap semua pelanggaran unique sebagai "tagihan sudah ada" dan menghitungnya `existing_invoices`. Padahal `invoices.invoice_no` juga unique. Penghitung nomor di memori dipakai sepanjang eksekusi (bisa lama di shared hosting), dan `GenerateInvoiceNumber` akan dipakai ulang F010/F011 untuk bulan yang sama (prefiks bulan sama, tanpa lock bersama). Tabrakan nomor lalu membuat santri tidak ditagih secara diam-diam: tanpa `skipped`, tanpa log, dan transaksi seluruh kelompok santri itu ikut dibatalkan. | Bedakan penyebab: setelah catch, cek ulang apakah tagihan aktif (`student_id`, `period`, `billing_group`) sudah ada. Bila ya, hitung `existing_invoices`. Bila tidak, muat ulang penghitung nomor dari database dan ulangi (batas wajar, mis. 3 kali), lalu lempar bila tetap gagal. Pertimbangkan alokasi nomor di dalam transaksi per santri. Tambahkan AC dengan tagihan berprefiks sama yang disisipkan di tengah eksekusi (atau penghitung basi): santri tetap mendapat tagihan bernomor baru dan tidak masuk `existing_invoices`. |
| 3 | minor | Algoritma langkah 6, catch unique | Transaksi dibuat per santri untuk semua kelompok; satu konflik membatalkan semua kelompok santri itu dan seluruhnya dihitung "existing", padahal kelompok lain mungkin belum ada. | Hitung per kelompok, atau jalankan ulang pengecekan sesuai saran #2. Bisa diputuskan saat implementasi. |
| 4 | minor | Prasyarat 2 (`Cache::lock` 600 detik), Risiko #4 | TTL 600 detik bisa habis sebelum eksekusi selesai untuk ribuan santri. Aksi panel sinkron juga terkena `max_execution_time` shared hosting (umumnya 30–60 detik) tanpa pesan jelas. | Pakai TTL lebih panjang atau perpanjang lock; catat di docblock bahwa eksekusi yang terputus aman diulang (idempotent). Pertimbangkan `set_time_limit(0)` di command dan batas jelas di Risiko #4. |
| 5 | minor | `CancelInvoice` + `LogsActivity` (AC-36) | Auto-log `updated` dari `LogsActivity` tidak membawa properti `reason`. Mekanisme penambahan `reason` tidak ditentukan dan bisa menghasilkan dua baris log (otomatis + manual). | Tentukan satu jalur: `activity()->tap(...)` pada log otomatis, atau update dibungkus `withoutLogs` lalu log manual `event('updated')` dengan `old.status`, `attributes.status`, `reason`. AC-36 harus menegaskan tepat satu baris log. |
| 6 | minor | Model data `invoices`, CLAUDE.md "Konvensi" (unique nullable memakai `COALESCE`) | Unique pada kolom nullable (`student_id`, `applicant_id`, `active_marker`) sengaja memakai semantik NULL-berbeda, bertentangan dengan pola `COALESCE(col, 0)` di CLAUDE.md. Alasannya benar (tagihan pendaftar/santri tidak boleh saling bentrok), tetapi hanya tersirat. | Tambahkan komentar di migration yang menyebut penyimpangan sadar ini. Entitas `invoices` di diagram Mermaid `docs/erd.md` juga belum memuat kolom `active_marker`; tambahkan saat implementasi. |
| 7 | minor | Model data `period`, AC-1/AC-56/AC-57 | Cast `date` Laravel menyimpan `Y-m-d 00:00:00` di SQLite, sedangkan MySQL/Postgres memotong ke tanggal. Unique tetap konsisten selama semua insert lewat model/factory, tetapi insert mentah di test bisa berbeda. | Test constraint memakai model/factory dengan `period` `startOfMonth()`. Hindari insert `DB::table` untuk `period`. |
| 8 | minor | AC-32, Algoritma langkah 5 | Spy `ResolveFeeRate::handle` hanya bekerja bila Action di-resolve dari container, bukan `new`. | Tulis di rencana bahwa `GenerateMonthlyInvoices` menerima `ResolveFeeRate`, `ResolveFeeOverride`, `ApplyFeeOverride`, `GenerateInvoiceNumber` lewat konstruktor. |
| 9 | minor | AC-16, Otorisasi | Bendahara berjenjang SLTP yang menekan "Buat tagihan bulanan" membuat tagihan semua jenjang, dan notifikasi menampilkan jumlah lintas jenjang. Sengaja dan idempotent, tetapi mungkin mengejutkan. | Dokumentasikan sebagai keputusan sadar (teks modal sudah menyebut "semua santri aktif"). Tidak perlu diubah. |

Temuan #1 dan #2 harus diperbaiki di rencana (perilaku, AC, dan rencana test) sebelum lanjut ke red-tester. Sisanya boleh ditangani saat implementasi.

## Review #2 — 2026-10-15

Verdict: APPROVED

Semua temuan Review #1 tertangani: major #1 (`lastSequence` dengan `Invoice::withoutUnitScope()` tanpa filter status, AC-59), major #2 (cek ulang tagihan aktif, muat ulang penghitung, maks. 3 percobaan, `number_conflict`, AC-60/61), minor #3 (transaksi per kelompok, AC-62), #4 (TTL 1800, `set_time_limit(0)` di command, batas panel), #5 (satu jalur log pembatalan, AC-36 tepat satu baris), #6 (komentar migration dan diagram ERD memuat `active_marker`), #7, #8, #9. Pemetaan AC ke test dan urutan implementasi sudah diperbarui.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Langkah 6, konflik unique | Cek ulang "tagihan aktif sudah ada" mengasumsikan konflik berasal dari `invoice_no` bila tagihan tidak ditemukan; pelanggaran unique lain (mis. `invoice_items`) juga jatuh ke jalur retry lalu `number_conflict`. Aman (tidak diam-diam) tetapi pesannya bisa menyesatkan. | Saat implementasi, bila `RuntimeException` dilempar sertakan pesan DB asli di log. |
| 2 | minor | AC-60/AC-61 | Partial mock `lastSequence` menguji perilaku internal; pastikan assert utamanya tetap pada hasil (nomor unik, tidak ada santri terlewat, lock terlepas). | Dijaga oleh red-tester. |

Tidak ada blocker atau major. Lanjut ke red-tester (RED).
