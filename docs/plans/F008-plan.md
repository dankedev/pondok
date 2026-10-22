# F008 keuangan-laporan — Laporan keuangan

Status rencana: APPROVED

Owner: diklaim orkestrator di `feature_list.json`. Dependensi: F007 (`done`).

## Ringkasan & tujuan

Bendahara membuka satu halaman "Laporan keuangan" di grup navigasi Keuangan untuk menjawab empat pertanyaan pada periode yang dipilih:

1. **Ringkasan tagihan**: berapa yang ditagihkan, berapa yang sudah terbayar, berapa sisa, berapa yang sudah lewat jatuh tempo (menunggak), dan berapa rasio koleksinya, per bulan dan totalnya.
2. **Santri menunggak teratas**: siapa yang tunggakannya paling besar per hari ini.
3. **Subsidi beasiswa**: berapa total potongan yang diberikan per program beasiswa dan per donatur/sponsor, ditambah total keringanan non-beasiswa.
4. **Penerimaan kas**: berapa uang yang benar-benar diterima (berdasarkan `paid_on`, hanya pembayaran berstatus `diterima` saat laporan dibaca), per hari, per metode, dan per jenis biaya.

Semua angka dihitung dengan agregasi SQL dari data snapshot (`invoices`, `invoice_items`, `payments`, `payment_allocations`), menghormati cakupan jenjang (`unit_user`) lewat global scope `Invoice`/`Payment`, dan portabel di SQLite, MySQL, dan Postgres. Perhitungan dikemas sebagai Action yang bisa dipanggil ulang oleh widget dashboard F018 tanpa UI. Laporan bisa diunduh sebagai CSV (stream, tanpa dependency baru).

Acuan: PRD 3.5 ("Laporan: tagihan bulan berjalan, terbayar vs menunggak, rasio koleksi, santri menunggak teratas, dan total subsidi beasiswa per program"), PRD 4 (audit, UU PDP), PRD 5 butir 1–2; ERD "Keuangan & Tagihan" (catatan `scholarships` "total subsidi per program atau per donatur", `invoice_items` snapshot, alokasi per jenis biaya, pembatalan pembayaran), tabel index (`payments (status, paid_on)` untuk laporan kas F008, `invoices (status, period)` untuk tunggakan/rasio koleksi); `feature_list.json` F008 `notes` (laporan kas memakai `paid_on` dan hanya pembayaran `diterima` saat dibaca); review F007 butir #6; CLAUDE.md "Otorisasi", "Konvensi kode & data"; design guide §0, §3, §5, §6, §7, §8, §13.

## Ruang lingkup

- Termasuk:
  - Value object filter `App\Actions\Keuangan\FinanceReportFilter` (validasi + query dasar bersama).
  - Action perhitungan: `SummarizeInvoices`, `ListTopArrears`, `SummarizeScholarshipSubsidies`, `SummarizeCashReceipts`, dan `ExportFinanceReportCsv`.
  - Filament custom page `App\Filament\Pages\FinanceReport` (`/admin/finance-report`) di grup Keuangan, dengan form filter, empat bagian laporan, empty state, dan aksi "Unduh CSV".
  - Permission `view:finance-report` (format page Shield) di `RolePermissionSeeder`, diberikan ke `bendahara` (dan otomatis `super-admin`); dataset `RolePermissionSeederTest` diperbarui.
  - Teks `lang/id/pondok.php` bagian `finance_report`; `navigation` tidak bertambah grup baru.
  - Satu activity log `keuangan` per unduhan CSV (data pribadi santri ikut terekspor).
- Tidak termasuk (eksplisit):
  - Widget dashboard (F018). F008 hanya menyediakan Action yang bisa dipakai ulang; tidak ada widget, tidak memakai `view-finance-metrics`.
  - PDF/Excel (`.xlsx`) dan library ekspor apa pun. Hanya CSV lewat `response()->streamDownload()`.
  - Laporan tagihan PSB/pendaftar tersendiri (F010/F011). Tagihan pendaftar ikut terhitung hanya sesuai aturan cakupan di bawah.
  - Laporan per jenjang berdampingan (perbandingan antarjenjang dalam satu tabel), grafik, laporan kas umum yayasan (F029), buku besar/jurnal.
  - Snapshot jenjang pada tagihan. Jenjang tagihan dibaca dari `students.unit_id` saat ini (sama dengan global scope `Invoice`); lihat Risiko #3.
  - Penjadwalan/pengiriman laporan otomatis (email/WhatsApp).
  - Pilihan tanggal "per hari ini" selain hari ini untuk tunggakan.
  - Perubahan pada `RecordPayment`, `CancelPayment`, generator, atau skema tabel F006/F007.

## Model data

Tidak ada tabel, kolom, atau index baru. Laporan memakai:

| Tabel | Kolom yang dibaca | Index yang dimanfaatkan |
|---|---|---|
| `invoices` | `id`, `student_id`, `applicant_id`, `period`, `billing_group`, `total`, `paid_total`, `status`, `due_on` | (`status`, `period`) |
| `invoice_items` | `invoice_id`, `fee_type_id`, `discount_amount`, `fee_override_id` | FK `invoice_id`, `fee_override_id` |
| `fee_overrides` | `id`, `scholarship_id` | PK |
| `scholarships` | `id`, `name`, `sponsor` | PK |
| `payments` | `id`, `invoice_id`, `amount`, `paid_on`, `method`, `status` | (`status`, `paid_on`), (`invoice_id`, `status`) |
| `payment_allocations` | `payment_id`, `invoice_item_id`, `amount` | unique (`payment_id`, `invoice_item_id`) |
| `students` | `id`, `unit_id`, `nis`, `name`, `deleted_at` | PK, `unit_id` |
| `units` | `id`, `name`, `sort_order` | PK |
| `fee_types` | `id`, `code`, `name`, `priority` | PK |

Aturan query (berlaku untuk semua Action):

- **Uang**: semua hasil `SUM`/`COUNT` di-cast `(int)` di PHP (Postgres mengembalikan `numeric` sebagai string; `SUM` himpunan kosong = `NULL` → 0). Tidak ada float; persen dihitung dengan `intdiv`.
- **Tanggal**: pembanding rentang setengah-terbuka dengan string `Y-m-d`, tanpa fungsi tanggal SQL dan tanpa `whereDate` (agar index `period`/`paid_on` tetap terpakai di MySQL dan aman untuk SQLite yang menyimpan cast `date` sebagai `Y-m-d 00:00:00`):
  - periode: `period >= 'YYYY-MM-01' AND period < '<bulan setelah periodTo>-01'`;
  - tanggal bayar: `paid_on >= 'YYYY-MM-01' AND paid_on < '<bulan setelah periodTo>-01'`;
  - lewat jatuh tempo: `due_on < '<asOf Y-m-d>'` (jatuh tempo hari ini belum menunggak; `'2026-10-15 00:00:00' < '2026-10-15'` bernilai salah di SQLite, sama dengan MySQL/Postgres).
- **Pengelompokan tanggal**: `GROUP BY period` / `GROUP BY paid_on` di SQL, lalu kunci baris dinormalkan di PHP (`CarbonImmutable::parse(...)->format('Y-m')` / `toDateString()`) dan baris berkunci sama dijumlahkan (tahan terhadap campuran `Y-m-d` dan `Y-m-d H:i:s`).
- **Pengurangan uang di SQL** selalu dalam bentuk `SUM(CASE WHEN total > paid_total THEN total - paid_total ELSE 0 END)` (selanjutnya ditulis `sisa`), selaras dengan `Invoice::remainingAmount()` (`max(0, ...)`). Pengurangan tidak pernah dievaluasi bila hasilnya negatif, sehingga kolom `unsigned` MySQL tidak melempar *out of range* walau invarian F007 (`paid_total <= total`) rusak, dan tanpa `CAST` yang tidak portabel. `outstanding` ringkasan = `SUM(sisa)` (bukan `billed - paid`).
- **Postgres**: setiap kolom non-agregat di `SELECT` ikut `GROUP BY`; urutan memakai `orderByRaw` dengan ekspresi agregat (bukan alias di dalam ekspresi).
- **Tanpa N+1**: data santri, jenjang, jenis biaya, dan program dimuat dengan satu query `whereIn` setelah agregasi (atau `join`), bukan per baris.

## Action class

Semua kelas di `app/Actions/Keuangan/`, di-resolve dari container, tanpa efek samping kecuali `ExportFinanceReportCsv` (activity log). Cakupan jenjang **tidak** dimatikan: semua query berangkat dari `Invoice::query()` / `Payment::query()` sehingga global scope `UnitScope` berlaku untuk user yang login. Tanpa user login (console, queue) data tidak disaring (perilaku `UnitScope` yang ada). Saklar modul tidak dicek di Action (dicek di page).

### `App\Actions\Keuangan\FinanceReportFilter` (final readonly value object, bukan Action)

Properti: `CarbonImmutable $periodFrom` (tanggal 1), `CarbonImmutable $periodTo` (tanggal 1), `?int $unitId`, `?string $billingGroup`.

- `static currentMonth(): self` — `periodFrom = periodTo = now()->startOfMonth()` (zona aplikasi `Asia/Jakarta`), tanpa jenjang dan kelompok.
- `static make(mixed $periodFrom, mixed $periodTo, mixed $unitId = null, mixed $billingGroup = null): self` — validasi, melempar `ValidationException` (pesan `pondok.finance_report.errors.*`):
  - `periodFrom`/`periodTo` wajib string `Y-m` valid (mis. `2026-10`) atau `CarbonInterface` → key `period_from`/`period_to`, `period_invalid`.
  - `periodFrom > periodTo` → key `period_to`, `period_order`.
  - Rentang lebih dari 12 bulan → key `period_to`, `period_span`. Selisih bulan dihitung eksplisit `(y2 - y1) * 12 + (m2 - m1)` (bukan `diffInMonths`, yang di Carbon 3 mengembalikan float bertanda); ditolak bila selisih ≥ 12.
  - `unitId` kosong/`''` → null; selain itu harus id di `Unit::query()->accessible()` → key `unit_id`, `unit_invalid` (jenjang di luar cakupan ditolak, bukan diam-diam kosong).
  - `billingGroup` kosong/`''` → null; di-`trim`; maks 30 karakter → key `billing_group`, `billing_group_invalid`. Nilai yang tidak ada di data tidak ditolak (hasilnya kosong).
- `periodEndExclusive(): CarbonImmutable` — `periodTo->addMonth()`.
- `months(): list<CarbonImmutable>` — setiap tanggal 1 dari `periodFrom` sampai `periodTo`.
- `invoiceOwnerQuery(): Builder<Invoice>` — `Invoice::query()` (global scope aktif) + filter jenjang (`whereIn('invoices.student_id', Student::withoutGlobalScopes()->where('unit_id', $unitId)->select('id'))`, termasuk santri diarsipkan; tagihan pendaftar dengan `student_id` NULL otomatis tersingkir) + filter `billing_group`. **Tanpa** filter periode dan status.
- `invoiceQuery(): Builder<Invoice>` — `invoiceOwnerQuery()` + `status <> 'batal'` + rentang `period`.
- `paymentQuery(): Builder<Payment>` — `Payment::query()` (global scope aktif) + `status = 'diterima'` + rentang `paid_on` (tanggal 1 `periodFrom` sampai sebelum tanggal 1 bulan setelah `periodTo`) + `whereIn('payments.invoice_id', invoiceOwnerQuery()->select('invoices.id'))` bila filter jenjang atau kelompok diisi.
- `toArray(): array{period_from: string, period_to: string, unit_id: ?int, billing_group: ?string}` (`Y-m`), dipakai log dan nama file.

### `App\Actions\Keuangan\SummarizeInvoices`

`handle(FinanceReportFilter $filter, ?CarbonInterface $asOf = null): array` (`$asOf` bawaan `today()`).

Himpunan dasar: `$filter->invoiceQuery()` (tagihan non-`batal` dengan `period` dalam rentang).

Definisi metrik:

| Metrik | Definisi |
|---|---|
| `invoice_count` | jumlah tagihan |
| `billed` | `SUM(total)` |
| `paid` (terbayar) | `SUM(paid_total)`, yaitu jumlah pembayaran `diterima` yang teralokasi ke tagihan tersebut **saat laporan dibaca**, tanpa melihat `paid_on` |
| `outstanding` (sisa) | `SUM(sisa)`; sama dengan `billed - paid` selama invarian F007 terjaga |
| `overdue` (menunggak) | `SUM(sisa)` untuk tagihan berstatus `belum`/`sebagian` dengan `due_on < asOf` |
| `not_yet_due` | `outstanding - overdue` |
| `overdue_invoice_count` | jumlah tagihan pada baris `overdue` |
| `overdue_student_count` | `COUNT(DISTINCT student_id)` tagihan menunggak dengan `student_id` tidak NULL |
| `collection_rate` | `intdiv(paid * 100, billed)` (persen bulat ke bawah, 0–100); `null` bila `billed = 0` |
| `by_status` | per status `belum`, `sebagian`, `lunas`: `count`, `total`, `paid` (selalu tiga kunci, nol bila tidak ada) |

Konsisten dengan `Invoice::isOverdue()` (status `belum`/`sebagian`, `due_on` sebelum hari ini). Tagihan total Rp 0 (beasiswa penuh) berstatus `lunas`, ikut `invoice_count` dan tidak mengubah rasio.

Output:
```
array{
  filter: array, as_of: string (Y-m-d),
  totals: array{invoice_count, billed, paid, outstanding, overdue, not_yet_due, overdue_invoice_count, overdue_student_count, collection_rate: ?int, by_status: array},
  periods: list<array{period: string (Y-m), invoice_count, billed, paid, outstanding, overdue, collection_rate: ?int}>
}
```
`periods` berisi **setiap** bulan dari `$filter->months()` berurutan naik, bulan tanpa tagihan bernilai nol dan `collection_rate` null. Jumlah query tetap: satu agregasi per status-periode (`GROUP BY period, status` dengan `SUM` bersyarat `CASE WHEN due_on < ? AND status IN (...)`) ditambah satu `COUNT(DISTINCT student_id)`; implementer boleh memilih bentuk lain selama jumlah query tidak bergantung jumlah data (AC-17).

### `App\Actions\Keuangan\ListTopArrears`

`handle(FinanceReportFilter $filter, ?int $limit = 10, ?CarbonInterface $asOf = null): array`

- Himpunan: `$filter->invoiceOwnerQuery()` + `student_id IS NOT NULL` + `status IN ('belum','sebagian')` + `due_on < asOf`. **Rentang periode filter diabaikan**: tunggakan adalah akumulasi semua periode per hari ini (Risiko #2). Filter jenjang dan kelompok tetap berlaku.
- `GROUP BY student_id`: `overdue_amount = SUM(sisa)`, `overdue_invoice_count = COUNT(*)`, `oldest_period = MIN(period)`.
- Urut `overdue_amount` turun, lalu `student_id` naik (deterministik). `$limit` null = semua (dipakai CSV); `$limit < 1` → `InvalidArgumentException`.
- Data santri dimuat satu query: `Student::withoutGlobalScopes()->withTrashed()->with('unit')->whereKey($ids)` (cakupan sudah dijamin oleh query tagihan).
- Output `list<array{student_id: int, nis: ?string, name: string, unit_name: ?string, is_archived: bool, overdue_amount: int, overdue_invoice_count: int, oldest_period: string (Y-m)}>`. Santri yang diarsipkan (soft delete) dan santri berstatus lulus/keluar/pindah tetap tampil.

### `App\Actions\Keuangan\SummarizeScholarshipSubsidies`

`handle(FinanceReportFilter $filter): array`

- Himpunan item: `invoice_items` yang `invoice_id` ada di `$filter->invoiceQuery()->select('invoices.id')` (non-`batal`, dalam rentang periode), `fee_override_id IS NOT NULL`, join `fee_overrides`.
- **Subsidi beasiswa** = `SUM(invoice_items.discount_amount)` untuk item yang override-nya punya `scholarship_id`. Nilai dibaca dari snapshot `discount_amount`, sehingga perubahan tarif atau nilai override sesudah tagihan dibuat tidak mengubah laporan.
- `programs`: `GROUP BY fee_overrides.scholarship_id` → `scholarship_id`, `name`, `sponsor` (dimuat satu query), `subsidy`, `item_count`, `beneficiary_count = COUNT(DISTINCT invoices.student_id)` (perlu join `invoices`). Hanya program yang punya minimal satu item pada himpunan (termasuk yang subsidinya Rp 0 karena mode nominal ≥ tarif). Urut `subsidy` turun, lalu `name` naik.
- `sponsors`: dikelompokkan di SQL dengan `GROUP BY scholarships.sponsor` memakai **nilai kolom apa adanya** (tanpa `TRIM`/`NULLIF`; `SaveScholarship` sudah men-`trim` dan mengubah string kosong menjadi NULL saat simpan). NULL → kunci `null` (label "Tanpa donatur"). Per sponsor: `sponsor`, `subsidy`, `program_count` (`COUNT(DISTINCT scholarships.id)`), `beneficiary_count` (`COUNT(DISTINCT invoices.student_id)` dalam query yang sama, sehingga santri penerima dua program dari donatur sama terhitung sekali). Urut `subsidy` turun, lalu `sponsor` naik, `null` paling akhir bila seri.
- `non_scholarship_relief`: `SUM(discount_amount)` dan `item_count` untuk item yang override-nya `scholarship_id IS NULL` (keringanan kesepakatan wali).
- `total_subsidy`: jumlah `programs.subsidy`.
- Item tanpa `fee_override_id` tidak dihitung sama sekali.

### `App\Actions\Keuangan\SummarizeCashReceipts`

`handle(FinanceReportFilter $filter): array`

- Himpunan: `$filter->paymentQuery()` — **hanya `status = 'diterima'` saat laporan dibaca**, berdasarkan `paid_on` (bukan `created_at`). Pembayaran yang dibatalkan kemudian otomatis hilang dari laporan bulan mana pun (F008 `notes`).
- `total` (`SUM(amount)`), `payment_count`.
- `by_method`: `tunai`, `transfer`, `gateway` — `count` dan `amount` (selalu tiga kunci).
- `by_day`: `GROUP BY paid_on` → `list<array{date: Y-m-d, count, amount}>`, hanya hari yang ada pembayaran, urut naik.
- `by_fee_type`: `payment_allocations` join `payments` (himpunan yang sama, lewat `whereIn payment_id` subquery) join `invoice_items` → `GROUP BY invoice_items.fee_type_id`: `fee_type_id`, `code`, `name` (dimuat satu query), `amount`. Urut `fee_types.priority` naik lalu `id`. Invarian: `SUM(by_fee_type.amount) = total` (F007 invarian alokasi).

### `App\Actions\Keuangan\ExportFinanceReportCsv`

`handle(FinanceReportFilter $filter, string $section, User $user): StreamedResponse`

- `$section` ∈ `ringkasan`, `tunggakan`, `subsidi`, `kas`; selain itu → `ValidationException` key `section`, `section_invalid`.
- Memakai Action di atas (tunggakan **tanpa limit**). Isi CSV: UTF-8 dengan BOM, pemisah `;` (Excel berlokal Indonesia), baris pertama header dari `pondok.finance_report.csv.*`, uang sebagai bilangan bulat tanpa format, tanggal `Y-m-d`, periode `Y-m`, persen bilangan bulat atau kosong.
  - `ringkasan`: satu baris per bulan + baris "Total".
  - `tunggakan`: NIS, nama, jenjang, diarsipkan (Ya/Tidak), jumlah tagihan, periode tertua, tunggakan.
  - `subsidi`: per program (program, donatur, penerima, item, subsidi), lalu baris kosong dan per donatur, lalu baris "Keringanan non-beasiswa".
  - `kas`: per hari (tanggal, jumlah pembayaran, nominal), lalu baris kosong, per metode, lalu per jenis biaya, lalu baris "Total".
- Pencegahan *formula injection*: sel teks yang diawali `=`, `+`, `-`, `@`, tab, atau CR diberi awalan `'`. Angka tidak diubah.
- Nama file: `laporan-keuangan-{section}-{periodFrom Y-m}-{periodTo Y-m}.csv`.
- Efek samping: tepat satu activity `activity('keuangan')->causedBy($user)->event('exported')->withProperties(['section' => ..., 'filter' => $filter->toArray(), 'rows' => <jumlah baris data>])->log(__('pondok.finance_report.activity_exported'))`. Ditulis saat Action dipanggil (sebelum stream dikirim).
- Isi dibangun ke array baris lebih dulu (method publik `rows(FinanceReportFilter, string $section): list<list<string|int|null>>`) agar bisa diuji tanpa membaca stream; `handle()` hanya menulis baris itu dengan `fputcsv($out, $row, ';', '"', '')` (escape kosong, RFC 4180, tanpa deprecation PHP 8.4) ke `php://output`.

## UI (Filament / Blade)

Rujukan design guide: §0 checklist, §3 (ikon Heroicon Outlined, kapital hanya di awal kalimat), §5 (uang `Rp 1.250.000` lewat `Payment::formatRupiah()` di Blade atau `->money('IDR', locale: 'id', decimalPlaces: 0)` di komponen Filament; persen `87%`; tanggal `15 Okt 2026`; periode `Oktober 2026` via `translatedFormat('F Y')`; kosong `-`), §6 (angka rata kanan, `tabular-nums`), §7 (empty state, modul mati, tanpa akses), §8 (label kata kerja + objek), §13 (alur anti-ui-slop, kontrak desain di bawah). Implementer wajib memanggil skill `/anti-ui-slop` bila tersedia dan mengecek tampilan desktop + 360px atau menyatakan belum dicek.

### Kontrak desain (§13.3)
- **Pengguna & tujuan**: bendahara (dan super-admin) melihat kondisi tagihan dan kas pada periode tertentu untuk dilaporkan ke pimpinan/yayasan.
- **Aksi utama**: memilih periode. Aksi sekunder (`gray`): "Unduh CSV".
- **Urutan informasi**: (1) filter; (2) baris stat ringkasan maks. 4: Ditagihkan, Terbayar, Menunggak, Rasio koleksi; (3) tabel ringkasan per bulan (hanya bila rentang > 1 bulan; untuk 1 bulan cukup rincian status); (4) santri menunggak teratas; (5) subsidi beasiswa per program, per donatur, dan keringanan non-beasiswa; (6) penerimaan kas (total, per metode, per jenis biaya, per hari).
- **State**: kosong per bagian; modul mati (403, menu hilang); tanpa akses (403, menu tidak tampil); filter tidak valid (error di field, angka lama tidak diganti); memuat (`wire:loading` pada area laporan).
- **360px**: filter satu kolom; tabel lebar dibungkus kontainer `overflow-x-auto` milik Filament/Tailwind tanpa scroll horizontal halaman.

### `App\Filament\Pages\FinanceReport` (`app/Filament/Pages/FinanceReport.php`, view `resources/views/filament/pages/finance-report.blade.php`)
- Slug `finance-report` → `/admin/finance-report`. Ikon `Heroicon::OutlinedDocumentChartBar`. Grup `__('pondok.navigation.keuangan')`, `navigationSort = 6` (setelah resource keuangan yang ada). Label navigasi dan judul `pondok.finance_report.title` ("Laporan keuangan").
- `canAccess(): bool` = `Setting::moduleEnabled('keuangan') && auth()->user()?->can('view:finance-report')`. Modul mati atau tanpa permission → 403 dan menu tidak tampil. (Bukan trait `HasPageShield`, agar saklar modul ikut dicek; nama permission tetap format page Shield sehingga Shield tidak membuat permission ganda.)
- Form filter (Filament Schema, `->live()`, tanpa tombol "Terapkan"): 
  - `period_from`, `period_to`: pilihan bulan (`Select` berisi 36 bulan terakhir s.d. 12 bulan ke depan, label `Oktober 2026`, nilai `Y-m`), bawaan bulan berjalan.
  - `unit_id`: `Select` dari `Unit::query()->accessible()->orderBy('sort_order')`, placeholder "Semua jenjang". Untuk user dengan satu jenjang tetap bisa dikosongkan (berarti semua jenjang dalam cakupannya).
  - `billing_group`: `Select` dari `Invoice::query()->distinct()->orderBy('billing_group')->pluck('billing_group')` (dalam cakupan), placeholder "Semua kelompok".
- Setiap perubahan filter membangun `FinanceReportFilter::make(...)`; `ValidationException` ditampilkan sebagai error field (pola `ShowsActionErrorsOnForm` atau `addError`), laporan tidak dihitung ulang. Hasil keempat Action disimpan di properti computed Livewire (sekali per render).
- Bagian dan isi:
  - **Stat**: Ditagihkan (`billed`), Terbayar (`paid`, deskripsi "Rasio koleksi 87%"), Menunggak (`overdue`, deskripsi "12 tagihan, 9 santri"), Sisa belum jatuh tempo (`not_yet_due`). Rasio `null` tampil `-`.
  - **Rincian status**: belum bayar / sebagian / lunas, jumlah tagihan dan nominal; label dari `pondok.invoice.statuses.*` (tanpa badge karena ini baris rekap, bukan status record).
  - **Ringkasan per bulan** (rentang > 1 bulan): kolom Periode, Tagihan, Ditagihkan, Terbayar, Sisa, Menunggak, Rasio.
  - **Santri menunggak teratas** (10 baris): NIS, Nama (+ keterangan "Diarsipkan" bila soft delete), Jenjang, Jumlah tagihan, Sejak (periode tertua), Tunggakan. Nama tertaut ke `StudentResource` view bila `StudentResource::canView()`; selain itu teks biasa. Judul bagian menyebut "per {tanggal hari ini}" dan bahwa filter periode tidak berlaku.
  - **Subsidi beasiswa**: tabel per program (Program, Donatur, Penerima, Subsidi) + baris total; tabel per donatur; satu baris "Keringanan non-beasiswa".
  - **Penerimaan kas**: total + jumlah pembayaran; per metode; per jenis biaya; per hari (Tanggal, Jumlah pembayaran, Nominal). Catatan kecil: "Hanya pembayaran berstatus diterima, berdasarkan tanggal bayar."
- Empty state per bagian (ikon + satu kalimat, tanpa tombol): `empty_invoices` "Belum ada tagihan pada periode ini.", `empty_arrears` "Tidak ada santri yang menunggak.", `empty_subsidies` "Belum ada subsidi beasiswa pada periode ini.", `empty_cash` "Belum ada pembayaran diterima pada periode ini."
- Header action `export` "Unduh CSV" (`gray`, ikon `Heroicon::OutlinedArrowDownTray`): modal dengan `Radio` `section` (Ringkasan tagihan, Santri menunggak, Subsidi beasiswa, Penerimaan kas), deskripsi "Berkas berisi data pribadi santri. Simpan dan bagikan seperlunya." `->authorize(fn (): bool => static::canAccess())` sehingga pemanggilan aksi Livewire setelah mount tetap memeriksa permission dan saklar modul (CSV memuat data pribadi). Memanggil `ExportFinanceReportCsv` dengan filter aktif. Tidak tampil bila filter sedang tidak valid. Tanpa `requiresConfirmation` tambahan (bukan aksi yang mengubah data).

### `lang/id/pondok.php` bagian `finance_report`
`title`, `filters.*` (period_from "Dari bulan", period_to "Sampai bulan", unit "Jenjang", all_units "Semua jenjang", billing_group "Kelompok tagihan", all_groups "Semua kelompok"), `stats.*`, `sections.*`, `columns.*`, `methods` (pakai `pondok.payment.methods.*` bila ada), `no_sponsor` "Tanpa donatur", `non_scholarship` "Keringanan non-beasiswa", `archived` "Diarsipkan", `total` "Total", `empty_*`, `export.*` (label, heading, description, sections.*), `csv.*` (header kolom), `activity_exported` "Mengunduh laporan keuangan", `errors.*` (`period_invalid`, `period_order`, `period_span`, `unit_invalid`, `billing_group_invalid`, `section_invalid`). Semua pesan error bisa ditindaklanjuti, mis. `period_span` "Rentang laporan maksimal 12 bulan. Persempit pilihan bulan."

## Permission & cakupan

- `RolePermissionSeeder`: konstanta baru `PAGE_PERMISSIONS = ['view:finance-report']`, ikut `allPermissions()`. `bendahara` mendapat `view:finance-report`. `super-admin` otomatis. Peran lain tidak berubah.
- `RolePermissionSeederTest`: dataset `bendahara` persis bertambah `view:finance-report`; dataset peran lain tetap.
- `config/filament-shield.php` tidak diubah (page Shield memakai prefix `view` dan subject class kebab, sehingga nama yang dihasilkan Shield sama: `view:finance-report`).
- Unduh CSV memakai permission yang sama (tidak ada permission ekspor terpisah).
- Cakupan jenjang: semua Action memakai `Invoice::query()`/`Payment::query()` (global scope aktif). User berjenjang tidak melihat tagihan/pembayaran santri jenjang lain maupun tagihan pendaftar (`student_id` NULL). Filter jenjang hanya menawarkan dan menerima `Unit::accessible()`.
- Saklar `modules.keuangan.enabled`: dicek di `FinanceReport::canAccess()`.
- F018 memanggil `SummarizeInvoices::handle(FinanceReportFilter::currentMonth())` di bawah permission `view-finance-metrics`-nya sendiri; F008 tidak menyentuh permission itu.

## Kriteria penerimaan (bisa dites)

Fixture umum: waktu dibekukan `2026-10-15 10:00` Asia/Jakarta; jenjang dari `UnitSeeder` (SD, SLTP, SLTA); tagihan dan item dibuat lewat factory dengan `total`, `paid_total`, `status`, `due_on` eksplisit (konsisten dengan invarian), pembayaran lewat `RecordPayment`/`CancelPayment` bila perilaku pembayaran relevan.

### Filter
- **AC-1** — `FinanceReportFilter::currentMonth()` pada 2026-10-15 → `periodFrom` dan `periodTo` = `2026-10-01`, `unitId` null, `billingGroup` null; `months()` = `[2026-10-01]`; `make('2026-08', '2026-10')->months()` berisi 3 bulan berurutan; `toArray()` = `['period_from' => '2026-08', 'period_to' => '2026-10', 'unit_id' => null, 'billing_group' => null]`.
- **AC-2** — `make()` menolak (dataset) dengan `ValidationException` pada key dan pesan `pondok.finance_report.errors.*`: `'2026-13'` / `'abc'` / null → `period_from` `period_invalid`; `('2026-10', '2026-09')` → `period_to` `period_order`; `('2025-10', '2026-10')` (13 bulan) → `period_to` `period_span`, sedangkan `('2025-11', '2026-10')` (12 bulan) diterima; id jenjang tidak ada → `unit_id` `unit_invalid`; user berjenjang SLTP memilih SLTA → `unit_id` `unit_invalid`; `billing_group` 31 karakter → `billing_group_invalid`. `unitId ''` dan `billingGroup ''` menjadi null.

### Ringkasan tagihan
- **AC-3** — Periode Oktober 2026: tagihan Okt `belum` 500.000, Okt `sebagian` 600.000 terbayar 200.000, Okt `lunas` 400.000, Okt `batal` 999.000, Sep `belum` 300.000, Nov `belum` 700.000 → `invoice_count` 3, `billed` 1.500.000, `paid` 600.000, `outstanding` 900.000; tagihan `batal`, September, dan November tidak terhitung. Semua nilai bertipe `int`.
- **AC-4** — `collection_rate` (dataset `[billed, paid] → rate`): `[300000, 200000] → 66`, `[1000, 999] → 99`, `[500000, 500000] → 100`, `[500000, 0] → 0`; tanpa tagihan → `null`; tagihan Rp 0 berstatus `lunas` saja → `invoice_count` 1, `billed` 0, `collection_rate` null.
- **AC-5** — Menunggak per `asOf` 2026-10-15: tagihan `belum` jatuh tempo 14 Okt (sisa 500.000) dan `sebagian` jatuh tempo 10 Okt (sisa 400.000) terhitung `overdue` 900.000, `overdue_invoice_count` 2; jatuh tempo **15 Okt** (hari ini) dan 20 Okt tidak menunggak; `lunas` jatuh tempo 1 Okt tidak menunggak; `not_yet_due = outstanding - overdue`. Dua tagihan menunggak milik santri yang sama → `overdue_student_count` 1. `asOf` eksplisit 2026-10-21 membuat tagihan jatuh tempo 20 Okt ikut menunggak.
- **AC-6** — `by_status` selalu punya kunci `belum`, `sebagian`, `lunas` dengan `count`, `total`, `paid` yang benar; status tanpa tagihan bernilai 0; tidak ada kunci `batal`.
- **AC-7** — Rentang Agu–Okt 2026 dengan tagihan hanya di Agustus dan Oktober → `periods` berisi tepat `2026-08`, `2026-09`, `2026-10` berurutan; September bernilai nol dan `collection_rate` null; jumlah `billed`/`paid` per bulan sama dengan `totals`.
- **AC-8** — Filter: `unitId` SLTP hanya menghitung tagihan santri yang `students.unit_id`-nya SLTP saat ini, termasuk santri yang diarsipkan (soft delete); `billingGroup = 'ASRAMA'` hanya menghitung tagihan kelompok itu; tagihan pendaftar (`student_id` NULL, `applicant_id` terisi) terhitung untuk user tanpa cakupan tanpa filter jenjang, dan tidak terhitung bila `unitId` diisi.

### Santri menunggak teratas
- **AC-9** — Santri A menunggak 900.000 (2 tagihan, periode tertua Agustus), B 1.200.000 (1 tagihan), C 900.000 dengan id lebih besar dari A, D hanya punya tagihan belum jatuh tempo, E hanya tagihan `batal` lewat jatuh tempo, F hanya tagihan `lunas` → hasil berurutan B, A, C; D, E, F tidak ada; baris A berisi `overdue_amount` 900.000, `overdue_invoice_count` 2, `oldest_period` `2026-08`, `nis`, `name`, `unit_name`. Filter periode Oktober saja tidak menghilangkan tunggakan Agustus (periode filter diabaikan), sedangkan `unitId` dan `billingGroup` diterapkan.
- **AC-10** — 12 santri menunggak → `handle($filter)` mengembalikan 10 baris teratas; `handle($filter, null)` mengembalikan 12; `handle($filter, 0)` → `InvalidArgumentException`. Santri yang diarsipkan dan santri berstatus `lulus` tetap tampil, santri diarsipkan dengan `is_archived` true. Tagihan pendaftar tidak pernah muncul.

### Subsidi beasiswa
- **AC-11** — Program "Beasiswa Tahfidz" (sponsor "Yayasan Contoh"): 2 santri, item Okt dengan `discount_amount` 250.000 dan 500.000; program "Yatim" (sponsor "Yayasan Contoh"): 1 item 300.000; program "Anak Ustadz" (sponsor null): 1 item 100.000; keringanan tanpa beasiswa: 1 item 50.000; item diskon pada tagihan `batal` 400.000 dan item periode September tidak terhitung; program tanpa item periode ini tidak muncul → `programs` = Tahfidz 750.000 (penerima 2, item 2), Yatim 300.000, Anak Ustadz 100.000 (urut subsidi turun); `total_subsidy` 1.150.000; `non_scholarship_relief` = 50.000 (1 item). Mengubah `fee_overrides.value`, `scholarships.default_value`, atau tarif setelah tagihan dibuat tidak mengubah angka.
- **AC-12** — `sponsors`: "Yayasan Contoh" 1.050.000 (`program_count` 2) lalu `null` 100.000; santri yang menerima Tahfidz dan Yatim sekaligus dihitung satu kali di `beneficiary_count` sponsor "Yayasan Contoh". Dua sponsor non-null dengan subsidi sama diurut nama sponsor naik (mis. "Donatur A" sebelum "Donatur B"). Program yang subsidinya Rp 0 (item ada, mode nominal ≥ tarif) tetap tampil dengan 0.

### Penerimaan kas
- **AC-13** — Laporan Oktober 2026: pembayaran `paid_on` 1 Okt 200.000 dan 31 Okt 300.000 terhitung; `paid_on` 30 Sep dan 1 Nov tidak; pembayaran `paid_on` 10 Okt yang **dicatat** (`created_at`) 2 Nov tetap masuk Oktober; pembayaran 15 Okt 100.000 yang kemudian dibatalkan lewat `CancelPayment` tidak terhitung → `total` 500.000, `payment_count` 2.
- **AC-14** — Rincian: `by_method` punya kunci `tunai`, `transfer`, `gateway` dengan `count`/`amount` benar; `by_day` hanya tanggal yang ada pembayaran, urut naik, format `Y-m-d`; `by_fee_type` dari alokasi pembayaran `diterima` (cicilan tagihan gabungan SPP+MAKAN teralokasi sesuai prioritas F007) dengan `SUM(by_fee_type.amount) = total`; alokasi milik pembayaran `batal` tidak terhitung; filter `unitId`/`billingGroup` membatasi pembayaran lewat tagihannya.

### Cakupan jenjang & portabilitas
- **AC-15** — User `bendahara` dengan cakupan SLTP (login): keempat Action hanya menghitung tagihan, item, dan pembayaran santri SLTP; tagihan/pembayaran santri SLTA dan tagihan pendaftar tidak terhitung. User `bendahara` tanpa baris `unit_user` menghitung semua jenjang. Tanpa user login (konteks console/F018 scheduler), data tidak disaring.
- **AC-16** — Efisiensi: dengan `DB::enableQueryLog()`, menjalankan keempat Action (tunggakan dengan limit 10) untuk fixture 3 santri dan fixture 30 santri menghasilkan jumlah query yang **sama**. Kedua fixture wajib berisi data di keempat bagian (ada tagihan, ada santri menunggak, ada item beasiswa dan keringanan non-beasiswa, ada pembayaran `diterima`), agar query pemuatan santri/program/jenis biaya ikut terhitung, dan tidak ada query yang memakai fungsi tanggal SQL (`date(`, `strftime`, `DATE_FORMAT`, `extract(`) pada SQL yang tercatat. Test hanya berjalan di SQLite; kepatuhan `GROUP BY`/`orderByRaw` untuk Postgres dan MySQL ditelaah manual oleh implementer dan red-tester VERIFY terhadap aturan query di bagian Model data.
- **AC-17** — Data campuran format tanggal (dua santri berbeda agar tidak melanggar unique `invoices_student_period_group_active_unique`): satu tagihan disisipkan lewat query builder dengan `period = '2026-10-01'` dan `due_on = '2026-10-10'` (tanpa jam), satu lewat Eloquent (SQLite menyimpan `2026-10-01 00:00:00`) → keduanya terhitung di periode `2026-10` dalam satu baris `periods`, keduanya terhitung menunggak, dan satu pembayaran dengan `paid_on` tanpa jam masuk `by_day` yang sama dengan pembayaran Eloquent di tanggal yang sama.

### Halaman
- **AC-18** — Akses: `bendahara` dan `super-admin` mendapat 200 di `/admin/finance-report`; `admin-sekolah`, `kesantrian`, `musyrif`, `admin-konten`, `admin-psb`, `ustadz-tahfidz`, `petugas-pos` mendapat 403 (dataset); tamu diarahkan ke halaman login. Menu "Laporan keuangan" tampil di grup Keuangan bagi `bendahara` dan tidak tampil bagi `admin-sekolah`.
- **AC-19** — Saklar `modules.keuangan.enabled` = false → `super-admin` mendapat 403 di `/admin/finance-report` dan menu "Laporan keuangan" tidak tampil di `/admin`.
- **AC-20** — Render (Livewire test, `bendahara` tanpa cakupan): bawaan filter bulan berjalan; tampil `Rp 1.500.000` (ditagihkan dari fixture AC-3), `40%` (rasio), nama santri menunggak teratas, nama program beasiswa, dan total kas; mengubah `period_from`/`period_to` ke September memperbarui angka; filter tidak valid (`period_from` 2026-10, `period_to` 2026-09) → `assertHasErrors(['period_to'])` tanpa exception, nilai `Rp 1.500.000` dari filter valid sebelumnya tetap tampil, dan jumlah query agregasi ke `invoices`/`payments` yang tercatat di `DB::getQueryLog()` selama update filter tidak valid itu = 0 (laporan tidak dihitung ulang).
- **AC-21** — Empty state: tanpa data sama sekali halaman tetap 200 dan menampilkan keempat teks `pondok.finance_report.empty_*`, rasio tampil `-`, tidak ada `Rp` bernilai negatif atau teks kunci lang mentah (`pondok.`).
- **AC-22** — Cakupan di halaman (acceptance F008): `bendahara` berjenjang SLTP melihat santri SLTP menunggak dan **tidak** melihat nama santri SLTA menunggak; nominal ditagihkan hanya dari SLTP; opsi filter jenjang hanya berisi SLTP; mengisi `unit_id` SLTA lewat Livewire menghasilkan error `unit_invalid`, bukan data SLTA.
- **AC-23** — Seeder: permission `view:finance-report` ada setelah `RolePermissionSeeder`; `bendahara` mendapatkannya; dataset seluruh peran di `RolePermissionSeederTest` persis (peran selain `bendahara` dan `super-admin` tidak mendapatkannya).

### Ekspor CSV
- **AC-24** — `ExportFinanceReportCsv::rows()`: `ringkasan` berisi header, satu baris per bulan, dan baris Total; `tunggakan` berisi **semua** santri menunggak (12 dari fixture AC-10, bukan 10); `subsidi` dan `kas` sesuai urutan di Action; uang berupa integer tanpa `Rp`/titik, tanggal `Y-m-d`; nama santri `=HYPERLINK("x")` dan `-Ahmad` diawali `'`; `section` tidak dikenal → `ValidationException` `section_invalid`. `handle()` menghasilkan `StreamedResponse` dengan nama file `laporan-keuangan-tunggakan-2026-10-2026-10.csv`, isi diawali BOM UTF-8 dan memakai pemisah `;`.
- **AC-25** — Aksi "Unduh CSV" di halaman (Livewire `callAction('export', ['section' => 'tunggakan'])`) mengunduh berkas bernama sesuai AC-24; tepat satu activity log `keuangan` dengan `event` `exported`, `causer` user, dan `properties.section`/`properties.filter`; user berjenjang SLTP yang mengekspor tidak mendapatkan baris santri SLTA (diuji lewat `rows()` dengan user login yang sama). Setelah halaman dimuat oleh `bendahara`, permission `view:finance-report` dicabut dari perannya (atau saklar `modules.keuangan.enabled` dimatikan) lalu `callAction('export', ...)` → aksi ditolak (tidak ada berkas terunduh, tidak ada activity `exported` baru).

## Rencana test

| AC | File | Nama test (usulan) |
|---|---|---|
| 1–2 | `tests/Feature/Actions/Keuangan/FinanceReportFilterTest.php` | `AC-1: defaults to the current month`, `AC-2: rejects invalid report filters` (dataset) |
| 3–8 | `tests/Feature/Actions/Keuangan/SummarizeInvoicesTest.php` | `AC-3: sums billed and paid for active invoices in range`, `AC-4: floors the collection rate` (dataset), `AC-5: counts overdue invoices before the as-of date`, `AC-6: breaks totals down by status`, `AC-7: returns every month in range`, `AC-8: applies unit and billing group filters` |
| 9–10 | `tests/Feature/Actions/Keuangan/ListTopArrearsTest.php` | `AC-9: ranks students by overdue amount`, `AC-10: limits rows and keeps archived students` |
| 11–12 | `tests/Feature/Actions/Keuangan/SummarizeScholarshipSubsidiesTest.php` | `AC-11: totals subsidies per scholarship program`, `AC-12: groups subsidies per sponsor` |
| 13–14 | `tests/Feature/Actions/Keuangan/SummarizeCashReceiptsTest.php` | `AC-13: counts received payments by paid date`, `AC-14: breaks cash down by method, day, and fee type` |
| 15–17 | `tests/Feature/Actions/Keuangan/FinanceReportScopeTest.php` | `AC-15: respects the unit scope of the logged-in user`, `AC-16: runs a constant number of portable queries`, `AC-17: handles dates stored with and without time` |
| 18–19, 23 | `tests/Feature/Filament/KeuanganAccessTest.php` (tambah) dan `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (ubah dataset) | `AC-18: lets bendahara open the finance report`, `AC-18: forbids the finance report to other roles` (dataset), `AC-19: hides the finance report when keuangan is disabled`, `AC-23` lewat dataset `bendahara` + `it('creates the finance report permission')` |
| 20–22, 25 | `tests/Feature/Filament/Pages/FinanceReportTest.php` | `AC-20: renders the report for the selected period`, `AC-21: shows empty states without data`, `AC-22: limits the report to the user units`, `AC-25: downloads the report as CSV and logs it` |
| 24 | `tests/Feature/Actions/Keuangan/ExportFinanceReportCsvTest.php` | `AC-24: builds CSV rows per section` |

Helper test baru (bila perlu) di `tests/Pest.php` dengan awalan `keuangan`, mis. `keuanganReportInvoice(Student $student, string $period, int $total, int $paid, string $status, string $dueOn, string $group = 'BULANAN'): Invoice`. Waktu dibekukan dengan `$this->travelTo(...)`.

## Urutan implementasi

1. `FinanceReportFilter` + teks `pondok.finance_report.errors.*` — AC-1, 2.
2. `SummarizeInvoices` — AC-3–8.
3. `ListTopArrears` — AC-9, 10.
4. `SummarizeScholarshipSubsidies` — AC-11, 12.
5. `SummarizeCashReceipts` — AC-13, 14.
6. Uji lintas Action (cakupan, jumlah query, format tanggal campuran) — AC-15–17; perbaiki Action bila perlu.
7. Permission `view:finance-report` di `RolePermissionSeeder` + dataset test — AC-23.
8. `FinanceReport` page (akses, menu, saklar modul) — AC-18, 19.
9. View laporan, filter, empty state, teks `lang/id` — AC-20–22.
10. `ExportFinanceReportCsv` + aksi "Unduh CSV" — AC-24, 25.
11. Cek visual desktop + 360px (atau laporkan belum dicek), checklist design guide §0, Pint, jalankan seluruh test Keuangan.

## Risiko & pertanyaan terbuka

Setiap butir punya default yang dipakai bila user tidak memutuskan lain.

1. **"Terbayar" pada ringkasan vs kas.** Ringkasan tagihan memakai `invoices.paid_total` (kondisi saat dibaca, tanpa melihat `paid_on`), sedangkan penerimaan kas memakai `payments.paid_on`. Angka keduanya untuk bulan yang sama bisa berbeda (tunggakan Agustus dibayar Oktober masuk "terbayar" Agustus dan "kas" Oktober). *Default:* tampilkan keduanya dengan keterangan singkat di masing-masing bagian.
2. **Tunggakan teratas mengabaikan filter periode.** *Default:* akumulasi semua periode per hari ini (filter jenjang dan kelompok tetap berlaku). Alternatif: hanya periode ≤ `periodTo`.
3. **Jenjang tagihan dibaca dari jenjang santri saat ini.** Santri yang naik SD → SLTP membawa tagihan lamanya ke SLTP dalam laporan (sama dengan global scope `Invoice`). *Default:* diterima. Snapshot `unit_id` di `invoices` butuh perubahan skema F006 dan bisa diusulkan sebagai entri baru bila dibutuhkan.
4. **Atribusi beasiswa mengikuti override saat ini.** Nominal subsidi adalah snapshot (`discount_amount`), tetapi programnya dibaca lewat `fee_overrides.scholarship_id` yang masih bisa diubah lewat `SaveFeeOverride`. *Default:* diterima; mengganti program pada override mengubah atribusi laporan masa lalu (tercatat di activity log override).
5. **Rasio koleksi dibulatkan ke bawah, tanpa desimal.** *Default:* `intdiv`, sehingga 99,9% tampil 99% dan 100% hanya bila lunas penuh. Nilai mentah `billed`/`paid` tersedia untuk F018.
6. **Rentang maksimal 12 bulan.** *Default:* 12 bulan untuk menjaga query dan tabel per hari tetap ringan di shared hosting.
7. **Tagihan pendaftar (F010).** *Default:* ikut terhitung untuk user tanpa cakupan jenjang tanpa filter jenjang; tidak pernah muncul di tunggakan per santri; tidak terlihat oleh user berjenjang. Laporan PSB tersendiri ada di fitur PSB.
8. **CSV.** *Default:* disediakan, pemisah `;` + BOM UTF-8 (Excel lokal Indonesia), uang integer polos, empat bagian dipilih saat unduh, tanpa permission terpisah, tetapi setiap unduhan dicatat di activity log karena memuat data pribadi (PRD 4, UU PDP).
9. **Unsigned subtraction di MySQL.** *Keputusan (review #1 minor #2):* sisa selalu dihitung `CASE WHEN total > paid_total THEN total - paid_total ELSE 0 END`, sehingga laporan tetap tampil walau invarian F007 rusak; tanpa `CAST ... AS SIGNED`.
10. **Index `due_on`.** Query tunggakan memakai index (`status`, `period`) lalu memfilter `due_on`. *Default:* tanpa index baru; bila data besar lambat, usulkan index (`status`, `due_on`) sebagai entri terpisah.
11. **Dependensi F007.** Sudah `done`; F008 sudah diklaim orkestrator.
12. **Normalisasi sponsor.** *Keputusan orkestrator (review #1 major #1):* sponsor dikelompokkan dengan nilai kolom apa adanya; normalisasi (trim, kosong → NULL) dijamin `SaveScholarship` saat simpan. Data yang masuk di luar Action (mis. impor SQL mentah) dengan variasi spasi akan tampil sebagai sponsor terpisah.

## Riwayat revisi

- r1 (DRAFT): versi awal oleh product-manager.
- r2 (DRAFT): revisi atas Review #1. #1 (major, keputusan orkestrator): sponsor dikelompokkan dengan nilai kolom apa adanya di SQL, NULL → "Tanpa donatur"; syarat trim/string kosong dihapus dari AC-12 (Risiko #12). #2: sisa memakai `CASE WHEN total > paid_total ...` di semua agregasi, `outstanding = SUM(sisa)`, Risiko #9 diperbarui. #3: `fputcsv(..., ';', '"', '')`. #4: aksi `export` `->authorize(fn () => static::canAccess())` + skenario `callAction` ditolak di AC-25. #5: `fee_types` tanpa `sort_order`, urut `priority`. #6: selisih bulan `(y2 - y1) * 12 + (m2 - m1)`. #7: tie-break sponsor naik (AC-12). #8: AC-16 mensyaratkan data di keempat bagian pada kedua fixture + telaah manual Postgres/MySQL. #9: AC-17 memakai dua santri berbeda. #10: AC-20 dengan assertion konkret (error `period_to`, nilai lama tetap, 0 query agregasi). #11: F007 `done`, Risiko #11 diperbarui.

## Review #1 — 2026-10-15

Verdict: CHANGES_REQUESTED

Diverifikasi: rentang setengah terbuka string `Y-m-d` terhadap kolom `date` SQLite yang tersimpan dengan jam (dicoba langsung di sqlite3): `'2026-10-01 00:00:00'` dan `'2026-10-31 00:00:00'` masuk `>= '2026-10-01' AND < '2026-11-01'`, `'2026-11-01 00:00:00'` keluar, `due_on < '2026-10-15'` salah untuk `'2026-10-15 00:00:00'`. Cakupan jenjang benar (semua query berangkat dari `Invoice::query()`/`Payment::query()`, subquery lewat `toSql()` ikut global scope). Definisi metrik konsisten dengan PRD 3.5 dan `notes` F008 (`paid_on`, hanya `diterima`; tagihan `batal` selalu `paid_total = 0` karena `CancelInvoice` menolak bila ada pembayaran). Subtraksi unsigned aman selama invarian F007 terjaga.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | `SummarizeScholarshipSubsidies` `sponsors` vs AC-12 | `beneficiary_count` sponsor dihitung di SQL `GROUP BY scholarships.sponsor` (nilai mentah), tetapi AC-12 mewajibkan `"  Yayasan Contoh "` digabung dengan `"Yayasan Contoh"` dan `''` digabung ke `null`, serta santri penerima dua program sponsor sama dihitung sekali. `COUNT(DISTINCT)` per nilai mentah tidak bisa dijumlahkan di PHP tanpa menghitung ganda santri yang muncul di dua varian penulisan, jadi spesifikasi saling bertentangan dan AC-12 tidak bisa dipenuhi apa adanya. `SaveScholarship` (baris 52-59) sudah men-trim saat simpan, jadi varian hanya muncul pada data mentah/factory. | Pilih satu: (a) kelompokkan di SQL dengan ekspresi portabel `NULLIF(TRIM(scholarships.sponsor), '')` (sama persis di SELECT dan GROUP BY, aman di SQLite/MySQL/Postgres) dan jadikan itu kunci `sponsors` serta `program_count`; atau (b) hapus syarat trim/kosong dari AC-12 karena normalisasi dijamin `SaveScholarship`. Rujuk keputusan di teks Action dan AC-12. |
| 2 | minor | Aturan query "Pengurangan uang" + Risiko #9 | `SUM(total - paid_total)` per baris di MySQL (kolom `unsigned`) membuat seluruh laporan error bila satu baris melanggar invarian, padahal laporan bendahara lebih baik tetap tampil. | Pakai `CASE WHEN total > paid_total THEN total - paid_total ELSE 0 END` di dalam `SUM` (portabel, tanpa CAST); selaras dengan `Invoice::remainingAmount()` yang memakai `max(0, ...)`. |
| 3 | minor | `ExportFinanceReportCsv` | Proyek berjalan di PHP 8.4; `fputcsv()` tanpa argumen `escape` eksplisit memicu deprecation 8.4 (default akan berubah). Rencana hanya menulis `fputcsv(..., ';')`. | Tulis `fputcsv($out, $row, ';', '"', '')` (escape kosong, perilaku RFC 4180). |
| 4 | minor | Header action `export` / otorisasi | Otorisasi hanya di `canAccess()` saat mount; pemanggilan aksi Livewire berikutnya dan Action sendiri tidak memeriksa permission/saklar, padahal CSV memuat data pribadi. | Beri `->authorize(fn () => static::canAccess())` (atau `->visible`) pada aksi `export` dan tambahkan test akses ditolak untuk `callAction` oleh peran tanpa permission. |
| 5 | minor | Model data (tabel baca) | Tabel menyebut `fee_types` punya `sort_order`; kolom yang ada hanya `priority` (migration fee_types), sedangkan `by_fee_type` memang diurut `priority`. | Hapus `sort_order` untuk `fee_types` dari tabel. |
| 6 | minor | `FinanceReportFilter::make` (aturan 12 bulan) | `diffInMonths` di Carbon 3 mengembalikan float bertanda; benar hanya karena kedua tanggal tanggal 1 dan urutan sudah divalidasi lebih dulu. | Hitung eksplisit `(y2 - y1) * 12 + (m2 - m1)` agar tidak bergantung perilaku versi Carbon. |
| 7 | minor | `sponsors` urutan | Urutan hanya `subsidy` turun lalu `null` terakhir; seri antar sponsor non-null tidak deterministik. | Tambah tie-break `sponsor` naik. |
| 8 | minor | AC-16 | Jumlah query konstan hanya terbukti bila kedua fixture (3 dan 30 santri) sama-sama punya tunggakan, subsidi, dan kas (bagian kosong melewati query pemuatan). Pengujian hanya di SQLite, jadi kepatuhan `GROUP BY` Postgres dan perilaku MySQL tidak terbukti oleh test. | Tegaskan di AC-16 bahwa kedua fixture berisi data di keempat bagian; implementer menelaah manual `GROUP BY`/`orderByRaw` untuk Postgres (sudah ada di aturan query). |
| 9 | minor | AC-17 | Dua tagihan dengan periode sama harus beda santri/kelompok (unique `invoices_student_period_group_active_unique`); belum disebut. | Tambahkan ke fixture AC-17: dua santri berbeda. |
| 10 | minor | AC-20 | "tanpa menghitung ulang" saat filter tidak valid tidak punya assertion konkret. | Ganti dengan assertion: error pada `period_to`, nilai `Rp` sebelumnya tetap tampil, dan spy/counter query agregasi tidak bertambah. |
| 11 | minor | Dependensi | F007 belum `done` (checklist 2). | Diterima per Risiko #11 dan keputusan orkestrator; RED tidak boleh mulai sebelum F007 `done`. |

Ringkasan: 1 major, 10 minor, 0 blocker. Setelah #1 diputuskan dan AC-12 diselaraskan, rencana siap APPROVED.

## Review #2 — 2026-10-15

Verdict: APPROVED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | `sponsors` / Risiko #12 | Major #1 teratasi (keputusan orkestrator): pengelompokan nilai kolom apa adanya, konsisten dengan `SaveScholarship` yang men-trim dan mengubah kosong menjadi NULL; AC-12 selaras. Data impor mentah dengan variasi spasi tampil sebagai sponsor terpisah (sudah dicatat di Risiko #12). | Tidak ada tindakan. |

Minor #2–#10 Review #1 terverifikasi diterapkan (CASE sisa, `fputcsv` escape kosong, `authorize` aksi export, `priority`, selisih bulan eksplisit, tie-break sponsor, AC-16/17/20). F007 berstatus `done`, jadi RED boleh dimulai.
