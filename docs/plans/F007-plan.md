# F007 keuangan-pembayaran — Pembayaran, cicilan & kuitansi

Status rencana: APPROVED

Owner: tim-agent-fase1. Dependensi: F006 (`done`).

## Ringkasan & tujuan

Bendahara mencatat pembayaran tagihan (tunai atau transfer) dari panel. Setiap pembayaran boleh sebagian (cicilan) dan dialokasikan otomatis ke item tagihan menurut `fee_types.priority` (angka kecil dilunasi dulu, keputusan F004), dicatat di `payment_allocations`, lalu `invoice_items.paid_amount`, `invoices.paid_total`, dan status tagihan (`belum` / `sebagian` / `lunas`) diperbarui otomatis lewat `Invoice::syncStatus()` (F006). Setiap pembayaran mendapat kuitansi bernomor unik yang bisa dicetak sebagai halaman HTML siap cetak (A5, hitam-putih), dengan nominal angka dan terbilang.

Pembayaran tidak pernah dihapus. Pembayaran yang salah input dibatalkan (status `batal`): baris pembayaran, nomor kuitansi, alokasi, dan bukti transfer tetap tersimpan, sedangkan terbayar per item dan per tagihan dikurangi kembali. Bukti transfer disimpan di disk `private` dan hanya bisa diunduh lewat route yang memeriksa permission dan cakupan jenjang. Semua pencatatan dan pembatalan tercatat di activitylog (`keuangan`).

Acuan: PRD 3.5 ("Pembayaran tunai, transfer, atau payment gateway, termasuk cicilan yang dialokasikan ke item sesuai prioritas. Setiap pembayaran mendapat kuitansi bernomor."), PRD 5 butir 4, PRD 7 (format nomor kuitansi masih terbuka); ERD "Keuangan & Tagihan" (`payments`, `payment_allocations`, catatan alokasi cicilan), "Tabel bawaan" (`activity_log` untuk audit `payments`), "Konvensi umum" (tidak dihapus, status batal, `restrictOnDelete`); CLAUDE.md "Aturan bisnis utama — Cicilan", "Testing — Alokasi cicilan berdasarkan prioritas"; design guide §4, §5, §7, §8 ("Catat pembayaran", "Cetak kuitansi", batalkan pembayaran wajib konfirmasi), §11 (dokumen cetak).

## Ruang lingkup

- Termasuk:
  - Migration, model, factory: `payments`, `payment_allocations`.
  - Action di `app/Actions/Keuangan/`: `RecordPayment`, `CancelPayment`, `AllocatePaymentToItems` (perhitungan murni), `GenerateReceiptNumber`.
  - Kolom tambahan di `payments` di luar ERD: `status` (`diterima` | `batal`, bawaan `diterima`), `cancelled_at`, `cancelled_by`, `cancel_reason` (lihat Model data dan Risiko #1). ERD diperbarui saat implementasi.
  - Global scope cakupan jenjang pada `Payment` (lewat tagihan, lalu santri).
  - Panel: aksi "Catat pembayaran" di `InvoiceResource` (detail dan baris daftar), kolom "Terbayar" pada tabel item di detail tagihan, relation manager pembayaran di detail tagihan, `PaymentResource` ("Pembayaran": daftar dan detail, tanpa Create/Edit/Delete), aksi "Batalkan pembayaran", "Cetak kuitansi", "Unduh bukti transfer".
  - Halaman cetak kuitansi (Blade khusus cetak, tanpa Vite dan tanpa aset eksternal) dan route unduh bukti transfer, keduanya terdaftar sebagai authenticated route panel `admin`.
  - Permission dan policy `Payment` (aksi tambahan `cancel`), pembagian ke `bendahara`, dataset `RolePermissionSeederTest` diperbarui.
  - Relasi baru: `Invoice::payments()`, `InvoiceItem::allocations()`, `User::receivedPayments()`, `User::cancelledPayments()`; tombol Hapus `EditUser` disembunyikan bila user dirujuk `received_by` atau `cancelled_by`.
  - Teks `lang/id/pondok.php` bagian `payment` dan `receipt`; design guide §4 ditambah baris status pembayaran `diterima`.
- Tidak termasuk (eksplisit):
  - Payment gateway, virtual account, callback, dan pengisian `gateway_ref` (F020). F007 hanya menyediakan nilai enum `gateway` dan kolom `gateway_ref`; `RecordPayment` menolak metode `gateway`.
  - Notifikasi WhatsApp/email kuitansi (F021) dan portal wali (F022).
  - PDF. Tidak ada library PDF terpasang (`composer.json`) dan dependency baru butuh persetujuan; kuitansi berupa halaman cetak HTML (browser "Cetak / Simpan sebagai PDF"). Service PDF bisa diusulkan terpisah.
  - Satu pembayaran untuk beberapa tagihan sekaligus, kelebihan bayar/deposit/titipan (tabungan F028). Satu pembayaran = satu tagihan, maksimal sisa tagihan.
  - Mengubah pembayaran yang sudah dicatat. Koreksi = batalkan lalu catat ulang.
  - Alokasi manual oleh bendahara (memilih item yang dibayar). Alokasi selalu otomatis menurut prioritas.
  - Realokasi pembayaran lain saat satu pembayaran dibatalkan (lihat Risiko #2).
  - Laporan kas harian, rekap per jenis biaya, rasio koleksi (F008). Index disiapkan.
  - Halaman pengaturan identitas lembaga (alamat, telepon, logo); kuitansi membaca `settings` bila ada, tanpa UI baru (profil sekolah ada di F015/konten).
  - Pembayaran tagihan pendaftar dari portal PSB (F010/F011). `RecordPayment` tidak membedakan tagihan santri atau pendaftar, tetapi UI cakupan jenjang mengikuti `Invoice` (tagihan pendaftar tidak terlihat oleh user berjenjang).

## Model data

Semua tabel memakai `timestamps`. Tanpa soft delete. Uang `unsignedBigInteger` rupiah. Semua FK `restrictOnDelete`. Perbandingan tanggal memakai string `Y-m-d`.

### `payments` — model `App\Models\Payment`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| receipt_no | string(20), unique | `KW-YYYYMM-NNNNN`, lihat `GenerateReceiptNumber` |
| invoice_id | foreignId → invoices, `restrictOnDelete` | |
| amount | unsignedBigInteger | > 0, dijaga Action |
| paid_on | date | tanggal uang diterima (boleh mundur, tidak boleh masa depan) |
| method | enum `tunai`, `transfer`, `gateway` | `gateway` dicadangkan untuk F020 |
| proof_path | string, nullable | path relatif di disk `private`, folder `payment-proofs/{Y}/{m}/` |
| gateway_ref | string, nullable | F020 |
| received_by | foreignId → users, nullable, `restrictOnDelete` | selalu terisi oleh F007; nullable dicadangkan untuk callback gateway F020 agar tidak perlu rebuild tabel SQLite nanti |
| note | text, nullable | maks 1000 karakter (dijaga Action) |
| status | enum `diterima`, `batal`, bawaan `diterima` | **tambahan di luar ERD** |
| cancelled_at | timestamp, nullable | **tambahan** |
| cancelled_by | foreignId → users, nullable, `restrictOnDelete` | **tambahan** |
| cancel_reason | string(255), nullable | **tambahan** |

Index: (`invoice_id`, `status`) untuk daftar pembayaran per tagihan dan penjumlahan pembayaran aktif; (`status`, `paid_on`) untuk laporan kas F008.

Konstanta: `STATUS_RECEIVED = 'diterima'`, `STATUS_CANCELLED = 'batal'`, `STATUSES`; `METHOD_CASH = 'tunai'`, `METHOD_TRANSFER = 'transfer'`, `METHOD_GATEWAY = 'gateway'`, `METHODS`; `MANUAL_METHODS = [tunai, transfer]`. Cast: `amount` integer, `paid_on` date, `cancelled_at` datetime. `status` bawaan `diterima` lewat `$attributes`.

Relasi: `invoice()` BelongsTo, `allocations()` HasMany `PaymentAllocation`, `receiver()` BelongsTo `User` (`received_by`), `canceller()` BelongsTo `User` (`cancelled_by`).

Method: `isCancellable(): bool` (status `diterima`), `statusColor()` (`diterima` success, `batal` danger), `statusLabel()`, `methodLabel()`, `amountInWords(): string` (`Number::spell($amount, 'id')`, huruf pertama kapital, ditambah "rupiah" lewat `__('pondok.payment.amount_in_words', ['words' => ...])`; ext-intl sudah dipakai Filament dan `Number::currency`).

Cakupan jenjang: `use HasUnitScope`; override `applyUnitScope()` menjadi `whereIn('payments.invoice_id', Invoice::withoutGlobalScopes()->whereIn('student_id', Student::withoutGlobalScopes()->whereIn('unit_id', $unitIds)->select('id'))->select('id'))`. Santri yang diarsipkan tetap terhitung; pembayaran tagihan pendaftar tidak terlihat oleh user berjenjang (sama dengan `Invoice`).

Activitylog: `LogsActivity`, `useLogName('keuangan')`, `logOnly(['status'])`, `logOnlyDirty()`, `dontSubmitEmptyLogs()` sebagai jaring pengaman. Kedua Action membungkus penyimpanan dengan `activity()->withoutLogs()` dan menulis tepat satu log manual (pola `CancelInvoice`).

### `payment_allocations` — model `App\Models\PaymentAllocation`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| payment_id | foreignId → payments, `restrictOnDelete` | |
| invoice_item_id | foreignId → invoice_items, `restrictOnDelete` | |
| amount | unsignedBigInteger | > 0 |

- unique (`payment_id`, `invoice_item_id`).
- Tidak punya status sendiri: alokasi **berlaku** bila pembayarannya `diterima`. Baris alokasi tidak pernah diubah atau dihapus, termasuk saat pembatalan (riwayat utuh, kuitansi lama bisa dicetak ulang persis).
- Relasi `payment()`, `invoiceItem()`. Tanpa activitylog sendiri (tercakup di log pembayaran).

### Invarian (dijaga Action, diuji AC-28)
- Per pembayaran: `sum(allocations.amount) = payments.amount`.
- Per item: `invoice_items.paid_amount = sum(alokasi dari pembayaran diterima)` dan `0 <= paid_amount <= amount`.
- Per tagihan: `invoices.paid_total = sum(payments.amount diterima) = sum(items.paid_amount)` dan `paid_total <= total`; status dari `syncStatus()`.

### Perubahan pada model lama
- `Invoice`: relasi `payments()` HasMany; method `remainingAmount(): int` (`max(0, total - paid_total)`).
- `InvoiceItem`: relasi `allocations()` HasMany; method `remainingAmount(): int`.
- `User`: `receivedPayments()`, `cancelledPayments()`.

### Factory
`PaymentFactory` (tagihan dari factory, `receipt_no` unik berurutan, `method` tunai, `paid_on` hari ini, `received_by` user, state `cancelled()`, `transfer()`); `PaymentAllocationFactory`. Factory tidak memperbarui `paid_amount`/`paid_total`; test perilaku memakai `RecordPayment`.

## Action class

Semua Action di-resolve dari container (dependensi lewat konstruktor), sehingga partial mock di AC-19 bekerja.

### `App\Actions\Keuangan\AllocatePaymentToItems` (murni, tanpa query dan tanpa simpan)
`handle(Collection $items, int $amount): array`
- Input: item tagihan dengan relasi `feeType` termuat dan `paid_amount` terbaru; nominal pembayaran.
- Urutan: `feeType.priority` naik, lalu `invoice_items.id` naik (prioritas sama → item lebih dulu dibuat). Prioritas dibaca dari `fee_types` **saat pembayaran** (bukan snapshot).
- Untuk tiap item dengan sisa `amount - paid_amount > 0`: alokasikan `min(sisa item, sisa bayar)`. Item Rp 0 dan item lunas dilewati; tidak ada alokasi bernilai 0.
- Output: `list<array{invoice_item_id: int, amount: int}>` dengan jumlah tepat `$amount`.
- `$amount` melebihi total sisa item → `LogicException` (tanda data tidak konsisten; `RecordPayment` sudah memvalidasi sisa tagihan lebih dulu).

### `App\Actions\Keuangan\GenerateReceiptNumber`
Pola `GenerateInvoiceNumber`. Format `KW-{Ym}-NNNNN` (5 digit nol di depan), `{Ym}` = **bulan pencatatan** (`now('Asia/Jakarta')`), bukan `paid_on`, agar nomor kuitansi naik sesuai urutan terbit.
- `lastSequence(CarbonInterface $month): int` — `Payment::withoutUnitScope()` tanpa filter status (`batal` tetap memakai nomornya), `where('receipt_no', 'like', 'KW-Ym-%')->max('receipt_no')`, 5 digit terakhir; 0 bila belum ada.
- `format(CarbonInterface $month, int $sequence): string` — di luar 1–99.999 → `LogicException`.
- `handle(CarbonInterface $month): string`.

### `App\Actions\Keuangan\RecordPayment`
`handle(Invoice $invoice, User $user, array $data): Payment`

`$data`: `array{amount: int|string, paid_on: string|CarbonInterface, method: string, proof?: UploadedFile|null, note?: string|null}`.

Langkah:
1. **Validasi isian** (`ValidationException`, pesan `pondok.payment.errors.*`, tanpa menyimpan apa pun):
   - `amount`: bilangan bulat ≥ 1 (`filter_var` `FILTER_VALIDATE_INT`; string `"1.5"`, `"abc"`, `0`, negatif ditolak) → key `amount`, `amount_invalid`.
   - `method`: harus `tunai` atau `transfer` → key `method`, `method_invalid` (`gateway` ditolak di sini).
   - `paid_on`: tanggal valid → `paid_on_invalid`; tidak setelah hari ini (`Asia/Jakarta`) → `paid_on_future`. Tidak ada batas bawah.
   - `note`: di-`trim`, kosong → null, maks 1000 → key `note`, `note_too_long`.
   - `proof`: hanya untuk `transfer` (tunai + bukti → key `proof`, `proof_not_allowed`); bila ada, mime `jpg`/`jpeg`/`png`/`pdf` dan ukuran ≤ 2048 KB → selain itu `proof_invalid`. Bukti transfer **opsional**.
2. **Simpan bukti** (bila ada) ke disk `private`, folder `payment-proofs/{Y}/{m}`, nama acak (`store()`/`hashName`). Disk publik tidak pernah dipakai.
3. **Transaksi dengan percobaan ulang nomor** (maks. 3 percobaan, pola F006). Di dalam `DB::transaction`:
   1. Kunci tagihan: `Invoice::withoutUnitScope()->lockForUpdate()->findOrFail($invoice->getKey())`. Semua validasi berikut memakai baris terkunci, bukan instance yang mungkin basi.
   2. Status `batal` → key `invoice`, `invoice_cancelled`. Sisa (`total - paid_total`) = 0 → key `invoice`, `already_paid`. `amount > sisa` → key `amount`, `exceeds_remaining` (pesan menyebut sisa dalam format `Rp 700.000`).
   3. Muat item tagihan (`with('feeType')`), hitung alokasi lewat `AllocatePaymentToItems`.
   4. Nomor kuitansi dari `GenerateReceiptNumber::handle(now)`.
   5. Di dalam `activity()->withoutLogs()`: buat `Payment` (`status` `diterima`, `received_by` = user), buat baris `payment_allocations`, tambah `paid_amount` tiap item teralokasi, tambah `paid_total` tagihan, `syncStatus()`, simpan.
   6. Satu log manual: `activity('keuangan')->performedOn($payment)->causedBy($user)->event('created')->withProperties([...])->log(__('pondok.payment.activity_recorded'))`. Properties: `receipt_no`, `invoice_id`, `invoice_no`, `amount`, `method`, `paid_on` (`Y-m-d`), `allocations` (`list{invoice_item_id, fee_type_id, amount}`), `invoice_status` (`{old, new}`).
   - `UniqueConstraintViolationException` ditangkap **di luar** closure transaksi (aman di Postgres). Satu-satunya unique yang mungkin dilanggar adalah `receipt_no` (alokasi baru selalu milik pembayaran baru), jadi langsung coba lagi dengan penghitung yang dibaca ulang. Setelah 3 kali gagal → `RuntimeException` dengan pesan `pondok.payment.errors.receipt_number_conflict`; pesan DB asli ditulis ke `Log::error`.
4. **Pembersihan**: bila langkah 1–3 berakhir dengan exception apa pun setelah bukti tersimpan, file bukti dihapus dari disk `private` sebelum exception diteruskan.
5. Kembalikan `Payment` dengan `allocations` termuat; instance `$invoice` disinkronkan dari baris terbaru (`setRawAttributes`, pola `CancelInvoice`).

Status saklar modul tidak dicek di Action (dicek di panel dan route).

### `App\Actions\Keuangan\CancelPayment`
`handle(Payment $payment, User $user, string $reason): Payment`
1. `reason` di-`trim`, wajib, maks 255 → key `reason`, `reason_invalid`.
2. `DB::transaction`: kunci tagihan dulu, lalu pembayaran (urutan kunci sama dengan `RecordPayment`: tagihan → pembayaran, mencegah deadlock): `Invoice::withoutUnitScope()->lockForUpdate()->findOrFail($payment->invoice_id)`, `Payment::withoutUnitScope()->lockForUpdate()->findOrFail($payment->getKey())`.
3. Status sudah `batal` → key `payment`, `already_cancelled`.
4. Di dalam `withoutLogs`: `status = batal`, `cancelled_at = now()`, `cancelled_by`, `cancel_reason`; untuk tiap alokasi kurangi `paid_amount` item (hasil negatif → `LogicException`, tidak mungkin bila invarian terjaga); kurangi `paid_total` tagihan; `syncStatus()`; simpan.
5. Baris alokasi, nomor kuitansi, dan file bukti **tidak** diubah atau dihapus. Pembayaran lain pada tagihan yang sama **tidak** dialokasikan ulang.
6. Satu log manual: `event('updated')`, `performedOn($payment)`, `causedBy($user)`, properties `old.status` `diterima`, `attributes.status` `batal`, `reason`, `invoice_id`, `invoice_status` (`{old, new}`).
7. Sinkronkan instance `$payment` dari baris terbaru.

Akibat ke F006 (tidak mengubah kode F006): tagihan yang semua pembayarannya dibatalkan kembali `paid_total = 0`, sehingga bisa dibatalkan (`CancelInvoice`) dan generator bisa menambah item susulan lagi.

## UI (Filament / Blade)

Rujukan design guide: §0 checklist, §3, §4 (badge; baris baru "Pembayaran | diterima | `success`", baris "Tagihan / pembayaran | batal | `danger`" sudah ada), §5 (uang `->money('IDR', locale: 'id', decimalPlaces: 0)`, tanggal `j M Y`, tanggal+jam `j M Y, H.i`, periode `F Y`), §6 (tabel), §7 (state), §8 (label kata kerja + objek, konfirmasi), §11 (dokumen cetak). Implementer wajib memakai skill `/anti-ui-slop` bila tersedia.

### `InvoiceResource` (perubahan)
- `InvoiceResource::recordPaymentAction()` — label "Catat pembayaran", ikon `Heroicon::OutlinedBanknotes`, `primary`, `->authorize(fn () => auth()->user()->can('create', Payment::class))` (ditambah saklar modul lewat resource), tersembunyi bila status `batal` atau `lunas`. Modal: heading "Catat pembayaran", deskripsi akibat ("Pembayaran dialokasikan otomatis ke rincian tagihan sesuai prioritas dan mendapat kuitansi bernomor. Pembayaran yang salah tidak bisa dihapus, hanya dibatalkan."), teks sisa tagihan. Field:
  - `amount` `TextInput` numeric, `prefix('Rp')`, wajib, bawaan = sisa tagihan, helper "Sisa tagihan Rp …".
  - `paid_on` `DatePicker`, wajib, bawaan hari ini, `maxDate(today())`, tampilan `j M Y`.
  - `method` `Radio`/`ToggleButtons` tunai | transfer, bawaan tunai, `live()`.
  - `proof` `FileUpload`, tampil hanya bila transfer, `storeFiles(false)` (file diteruskan ke Action sebagai `TemporaryUploadedFile`, disimpan oleh Action), `acceptedFileTypes` jpg/png/pdf, `maxSize(2048)`, opsional.
  - `note` `Textarea`, opsional, maks 1000.
  - Submit "Catat pembayaran". Menjalankan `RecordPayment` lewat `runShowingFormErrors`. Error key `invoice` (tagihan dibatalkan/lunas di tempat lain) → notifikasi `danger` lalu modal ditutup (pola `cancelAction`). `RuntimeException` `receipt_number_conflict` → notifikasi `danger`, `halt()`. Sukses → notifikasi `success` "Pembayaran Rp 500.000 dicatat dengan kuitansi KW-202610-00001." dengan aksi notifikasi "Cetak kuitansi" (URL kuitansi, tab baru).
- `ViewInvoice` header: `recordPaymentAction()` (utama) lalu `cancelAction()`. `ListInvoices` baris: `recordPaymentAction()` di dalam `ActionGroup` yang sama dengan "Batalkan tagihan".
- Tabel item di infolist: tambah kolom "Terbayar" (`paid_amount`, uang, rata kanan, `tabular-nums`).
- `getRelations()`: `PaymentsRelationManager`.

### `InvoiceResource\RelationManagers\PaymentsRelationManager`
- Judul "Pembayaran". Hanya baca: tidak ada Create, Edit, Delete, Associate, Attach (pakai `isReadOnly(): false` hanya bila aksi bisnis kustom tersembunyi oleh mode baca; tetap tanpa aksi bawaan pengubah data).
- Kolom: `receipt_no`, `paid_on` (`j M Y`), metode (label), `amount` (uang, kanan), `receiver.name`, status (badge). Urut `id` turun. Semua status tampil.
- Aksi baris (`ActionGroup`): "Lihat" (ke detail `PaymentResource`), "Cetak kuitansi" (URL tab baru), "Unduh bukti transfer" (tampil bila `proof_path`), "Batalkan pembayaran".
- Empty state: "Belum ada pembayaran untuk tagihan ini."

### `PaymentResource` (`app/Filament/Resources/Payments/`)
- `BelongsToModule` (`keuangan`), `ShowsActionErrorsOnForm`, grup `pondok.navigation.keuangan`, `navigationSort = 1` (tepat di bawah Tagihan; Jenis biaya, Tarif, Program beasiswa, Keringanan digeser +1 menjadi 2–5), ikon `Heroicon::OutlinedBanknotes`, label "Pembayaran", `$hasTitleCaseModelLabel = false`, `recordTitleAttribute` `receipt_no`.
- Halaman: `ListPayments`, `ViewPayment`. Tidak ada halaman Create/Edit dan tidak ada `DeleteAction`/`DeleteBulkAction`.
- Tabel: `defaultSort` `id` turun; eager-load `invoice.student.unit`, `receiver`. Kolom: `receipt_no` (searchable), `invoice.student.nis` (searchable), `invoice.student.name` (searchable), `invoice.invoice_no` (searchable, toggleable), `paid_on` (`j M Y`, sortable), metode, `amount` (uang, kanan, `tabular-nums`, sortable), status (badge). `receiver.name` toggleable tersembunyi. Maks 7 tampil.
- Filter: status, metode, jenjang (`Unit::accessible()`, lewat `whereHas('invoice.student')`), rentang `paid_on` (dari/sampai).
- Empty state: ikon, "Belum ada pembayaran." + deskripsi "Pembayaran dicatat dari halaman tagihan." tanpa tombol.
- `ViewPayment` infolist: Ringkasan (nomor kuitansi, status, tanggal bayar, metode, nominal + terbilang, tagihan (nomor, tautan ke detail tagihan), santri (NIS — nama), jenjang, periode, diterima oleh, dicatat pada `created_at` `j M Y, H.i`, catatan); Pembatalan (tampil bila `batal`: waktu, oleh, alasan); Alokasi (tabel: rincian tagihan `invoiceItem.description`, jumlah). `resolveRecord` eager-load agar tanpa N+1.
- Header `ViewPayment`: "Cetak kuitansi" (`primary`, URL tab baru), "Unduh bukti transfer" (`gray`, tampil bila ada bukti), "Batalkan pembayaran".
- `PaymentResource::cancelAction()` (dipakai di relation manager, daftar, dan detail): label "Batalkan pembayaran", `danger`, ikon `Heroicon::OutlinedXCircle`, `requiresConfirmation()`, heading "Batalkan pembayaran", deskripsi "Terbayar pada tagihan dikurangi sebesar pembayaran ini dan status tagihan dihitung ulang. Kuitansi dan datanya tetap tersimpan dengan status batal.", field `reason` wajib maks 255. Tersembunyi bila `! isCancellable()`. Otorisasi policy `cancel`. Key error `payment` → notifikasi `danger`; key `reason` → error field.
- `PaymentResource::receiptUrl(Payment)` dan `proofUrl(Payment)` sebagai satu sumber URL.

### Halaman cetak kuitansi (Blade)
- View `resources/views/keuangan/receipt.blade.php`, dokumen HTML mandiri (`<html lang="id">`), **tanpa `@vite`, tanpa CDN, tanpa JS selain `onclick="window.print()"`** pada tombol "Cetak" yang disembunyikan di `@media print`.
- Gaya `<style>` kecil: `@page { size: A5; margin: 12mm; }`, tanpa warna (tidak ada hex/`rgb()`; garis memakai `currentColor`), aman hitam-putih (§11, §12).
- Isi: kop (`school.name`; `school.address` dan `school.phone` bila ada di `settings`; logo `school.logo_path` dari disk `public` bila ada), judul "Kuitansi", nomor kuitansi, tanggal bayar (`j M Y`), "Diterima dari" (nama wali kontak tagihan santri, `guardians` pivot `is_billing_contact`; bila tidak ada, nama santri), santri (NIS — nama, jenjang), nomor tagihan dan periode (`F Y`), tabel alokasi (deskripsi item, jumlah), total `Rp 500.000`, terbilang ("Lima ratus ribu rupiah"), metode, catatan (bila ada), petugas (`receiver.name`), dicetak pada (`j M Y, H.i`). Pembayaran `batal`: label teks "DIBATALKAN" di bawah judul beserta tanggal dan alasan (tanpa coretan).
- Format uang lewat helper bersama (`Number::currency(..., in: 'IDR', locale: 'id', precision: 0)` dibungkus satu method, mis. `Payment::formatRupiah()` atau helper yang sudah ada), bukan `number_format` di view.

### Route (authenticated route panel `admin`)
Didaftarkan lewat `AdminPanelProvider::authenticatedRoutes()` sehingga memakai middleware auth panel (tamu dialihkan ke login panel) dan `canAccessPanel`. Path tetap (dipakai test):
- `GET /admin/receipts/{payment}` → `App\Http\Controllers\Keuangan\ShowPaymentReceipt` (invokable).
- `GET /admin/payment-proofs/{payment}` → `App\Http\Controllers\Keuangan\DownloadPaymentProof` (invokable).

Pemeriksaan di kedua controller, berurutan: modul `keuangan` mati → 403; route model binding memakai query `Payment` dengan global scope jenjang (di luar cakupan atau tidak ada → 404); `Gate::authorize('view', $payment)` → 403. Unduh bukti: `proof_path` null atau file tidak ada di disk `private` → 404; respons `Storage::disk('private')->download($path, "bukti-transfer-{receipt_no}.{ext}")` (lampiran, bukan inline) dengan header `X-Content-Type-Options: nosniff`.

### `lang/id/pondok.php`
Bagian `payment`: `label`, `plural`, kolom (`receipt_no`, `invoice`, `nis`, `student_name`, `paid_on`, `method`, `amount`, `amount_in_words` (`:words rupiah`), `status`, `received_by`, `recorded_at`, `note`, `proof`, `unit`, `period`, `cancelled_at`, `cancelled_by`, `cancel_reason`, `paid_amount` ("Terbayar")), `methods.{tunai,transfer,gateway}` ("Tunai", "Transfer", "Pembayaran online"), `statuses.{diterima,batal}` ("Diterima", "Batal"), `section_summary`, `section_cancellation`, `section_allocations`, `allocation_item`, `allocation_amount`, `record_action`, `record_heading`, `record_description`, `record_submit`, `remaining_helper` (`Sisa tagihan :amount`), `recorded` (`Pembayaran :amount dicatat dengan kuitansi :receipt_no.`), `print_action` ("Cetak kuitansi"), `download_proof_action` ("Unduh bukti transfer"), `cancel_action`, `cancel_heading`, `cancel_description`, `cancel_reason`, `cancel_submit`, `cancelled` (`Pembayaran :receipt_no dibatalkan.`), `empty`, `empty_description`, `empty_for_invoice`, `filter_paid_from`, `filter_paid_until`, `activity_recorded`, `activity_cancelled`, `errors.{amount_invalid, method_invalid, paid_on_invalid, paid_on_future, note_too_long, proof_not_allowed, proof_invalid, invoice_cancelled, already_paid, exceeds_remaining (:remaining), already_cancelled, reason_invalid, receipt_number_conflict}`. Pesan menyebut cara memperbaiki (§9), mis. `exceeds_remaining`: "Nominal melebihi sisa tagihan. Masukkan paling banyak :remaining."

Bagian `receipt`: `title` ("Kuitansi"), `number`, `date`, `received_from`, `student`, `invoice`, `period`, `for_payment`, `total`, `in_words`, `method`, `note`, `officer` ("Petugas"), `printed_at`, `cancelled` ("DIBATALKAN"), `cancelled_reason`, `print_button` ("Cetak").

## Permission & cakupan

- `RolePermissionSeeder::RESOURCE_MODELS` ditambah `'Payment'`; `EXTRA_RESOURCE_ACTIONS['Payment'] = ['cancel']` → `cancel:payment`.
- `PaymentPolicy` (pola `InvoicePolicy`): `viewAny`, `view`, `create`, `update`, `delete`, `deleteAny` memeriksa permission standar; `cancel(AuthUser, Payment)` → `cancel:payment`. `PaymentAllocation` tanpa resource dan policy.
- `bendahara` mendapat: `view-any:payment`, `view:payment`, `create:payment`, `cancel:payment`. Tidak mendapat `update`/`delete`/`delete-any` (pembayaran tidak diubah atau dihapus). `create:payment` dipakai aksi "Catat pembayaran" di halaman tagihan.
- `super-admin` otomatis semua. Peran lain tidak berubah (403).
- Dataset `bendahara` di `RolePermissionSeederTest` persis bertambah empat permission di atas.
- Halaman kuitansi dan unduh bukti memakai `view:payment` (policy `view`) ditambah cakupan jenjang. Tidak ada permission khusus cetak (keputusan default, Risiko #7).
- Cakupan jenjang: `Payment` global scope (lewat tagihan → santri). Action memakai `withoutUnitScope()` untuk baris yang dikunci dan untuk nomor kuitansi.
- Saklar `modules.keuangan.enabled`: `PaymentResource` dan aksi di `InvoiceResource` lewat `BelongsToModule`; kedua route cek manual → 403.

## Kriteria penerimaan (bisa dites)

Fixture umum: `tagihanFixture()` (tahun ajaran 2026/2027, SLTP, MAKAN prioritas 1 Rp 400.000, ASRAMA prioritas 2 Rp 300.000, SPP prioritas 3 Rp 500.000, grup `BULANAN`); santri mukim SLTP; tagihan Oktober 2026 dari generator (total Rp 1.200.000, 3 item). `Carbon::setTestNow('2026-10-15 10:00', 'Asia/Jakarta')`. User `bendahara`.

**Model data & constraint**
- AC-1: Dua `payments` dengan `receipt_no` sama → `UniqueConstraintViolationException`.
- AC-2: Dua `payment_allocations` dengan `payment_id` dan `invoice_item_id` sama → `UniqueConstraintViolationException`.
- AC-3: Menghapus `invoices` yang punya pembayaran, `payments` yang punya alokasi, `invoice_items` yang punya alokasi, dan `users` yang dirujuk `received_by` atau `cancelled_by` → `QueryException` (FK restrict); baris tetap ada.
- AC-4: `payments.amount` dan `payment_allocations.amount` bertipe integer (bukan decimal/float); `payments.status` bawaan `diterima`; kolom `status`, `cancelled_at`, `cancelled_by`, `cancel_reason`, `proof_path`, `gateway_ref` ada. `Payment` dan `PaymentAllocation` tidak memakai `SoftDeletes`.

**Alokasi berdasarkan prioritas (wajib CLAUDE.md)**
- AC-5: `AllocatePaymentToItems` dengan item MAKAN (p1, 400.000), ASRAMA (p2, 300.000), SPP (p3, 500.000), semua `paid_amount` 0, nominal 500.000 → `[MAKAN 400.000, ASRAMA 100.000]`; tidak ada entri SPP; jumlah = 500.000.
- AC-6: Lewat `RecordPayment`: bayar 500.000 lalu 500.000 → pembayaran kedua teralokasi ASRAMA 200.000 dan SPP 300.000; `paid_amount` akhir MAKAN 400.000, ASRAMA 300.000, SPP 300.000; tagihan `sebagian`, `paid_total` 1.000.000. Bayar 200.000 lagi → SPP 200.000, tagihan `lunas`.
- AC-7: Urutan item **tidak** mengikuti urutan pembuatan item: item dibuat berurutan SPP, ASRAMA, MAKAN (id SPP terkecil), bayar 400.000 → seluruhnya ke MAKAN.
- AC-8: Prioritas sama → item dengan `id` lebih kecil dulu. Item Rp 0 (keringanan nominal 0) dan item yang sudah lunas dilewati; tidak ada baris alokasi bernilai 0.
- AC-9: Prioritas dibaca saat pembayaran: setelah tagihan dibuat, prioritas SPP diubah menjadi 0; bayar 500.000 → seluruhnya ke SPP.
- AC-10: `AllocatePaymentToItems` dengan nominal melebihi total sisa item → `LogicException`.

**Catat pembayaran**
- AC-11: Bayar penuh 1.200.000 tunai, `paid_on` 2026-10-15 → satu `payments` (`receipt_no` `KW-202610-00001`, `amount` 1.200.000, `method` `tunai`, `status` `diterima`, `received_by` user, `proof_path` null), tiga alokasi, `paid_amount` tiap item = `amount`-nya, `paid_total` 1.200.000, status tagihan `lunas`.
- AC-12: Bayar 500.000 → status tagihan `sebagian`. Tagihan tidak bisa dibatalkan lagi (`CancelInvoice` → `has_payments`) dan generator ulang mencatat `invoice_locked` untuk item yang belum ada (perilaku F006 tetap).
- AC-13: Setelah bayar 500.000, bayar 700.001 → `ValidationException` key `amount` `exceeds_remaining`; tidak ada pembayaran atau alokasi baru, `paid_total`, `paid_amount`, dan status tidak berubah. Bayar tepat 700.000 → berhasil, `lunas`.
- AC-14: Tagihan `lunas` (termasuk tagihan total 0) → key `invoice` `already_paid`; tagihan `batal` → key `invoice` `invoice_cancelled`. Tanpa data baru.
- AC-15 (validasi isian, dataset): `amount` 0, -1, `"1.5"`, `"abc"` → `amount_invalid`; `method` `gateway` atau `cek` → `method_invalid`; `paid_on` 2026-10-16 → `paid_on_future`; `paid_on` `bukan-tanggal` → `paid_on_invalid`; `note` 1001 karakter → `note_too_long`. Error pada field masing-masing; tidak ada pembayaran. `paid_on` 2026-09-20 (mundur) → berhasil.
- AC-16 (instance basi): dua instance `Invoice` untuk tagihan yang sama; bayar 1.000.000 lewat instance A, lalu bayar 500.000 lewat instance B yang belum dimuat ulang → `exceeds_remaining`. Tagihan dibatalkan lewat instance lain lalu dibayar lewat instance basi → `invoice_cancelled`.
- AC-17 (bukti transfer, `Storage::fake('private')` dan `Storage::fake('public')`): transfer dengan `UploadedFile::fake()->image('bukti.jpg')` → file ada di disk `private` dengan path berawalan `payment-proofs/2026/10/`, `proof_path` terisi, disk `public` kosong. Transfer tanpa bukti → berhasil, `proof_path` null. Tunai dengan bukti → key `proof` `proof_not_allowed`. Bukti `.gif`/`.txt` atau > 2048 KB → `proof_invalid`. Pada setiap penolakan, disk `private` kosong.
- AC-18: Transfer dengan bukti tetapi nominal melebihi sisa → `exceeds_remaining` dan file bukti yang sempat tersimpan dihapus (disk `private` kosong).
- AC-19 (nomor kuitansi): berurutan `KW-202610-00001`, `-00002`; bila sudah ada `KW-202610-00007` (pembayaran `batal`), berikutnya `KW-202610-00008`; `setTestNow` 2026-11-02 → `KW-202611-00001`; `paid_on` September yang dicatat Oktober → prefiks `KW-202610-`. User berjenjang SLTP mencatat saat nomor tertinggi bulan itu milik pembayaran santri SLTA → nomor baru tetap unik dan lebih besar.
- AC-20 (konflik nomor): partial mock `GenerateReceiptNumber` di container yang `lastSequence` pertama mengembalikan 0 lalu memanggil implementasi asli, sementara `KW-202610-00001` sudah ada → pembayaran tercatat `KW-202610-00002`, `lastSequence` dipanggil 2 kali. Mock yang selalu 0 → `RuntimeException` (`receipt_number_conflict`) setelah tepat 3 percobaan; tidak ada pembayaran, alokasi, atau perubahan `paid_total`/`paid_amount`; file bukti dihapus.
- AC-21 (audit): satu `RecordPayment` menghasilkan **tepat satu** baris activity baru: log `keuangan`, event `created`, subject pembayaran, causer user, properties `receipt_no`, `invoice_no`, `amount`, `method`, `paid_on`, `allocations` (berisi `fee_type_id` dan `amount` per item), `invoice_status.old` `belum`, `invoice_status.new` `sebagian`. Tidak ada activity `updated` untuk tagihan.

**Batalkan pembayaran**
- AC-22: Bayar 500.000 (MAKAN 400.000, ASRAMA 100.000) lalu `CancelPayment` dengan alasan `"  salah input  "` → pembayaran `batal`, `cancelled_at` terisi, `cancelled_by` user, `cancel_reason` `salah input`; baris pembayaran, `receipt_no`, dan kedua baris alokasi tetap ada; `paid_amount` MAKAN dan ASRAMA kembali 0; `paid_total` 0; tagihan `belum`. File bukti (bila ada) tetap ada.
- AC-23 (batal di tengah): P1 500.000 (MAKAN 400.000, ASRAMA 100.000), P2 500.000 (ASRAMA 200.000, SPP 300.000); batalkan P1 → MAKAN 0, ASRAMA 200.000, SPP 300.000, `paid_total` 500.000, `sebagian`; alokasi P2 tidak berubah. Bayar P3 400.000 → seluruhnya ke MAKAN (prioritas tetap berlaku untuk sisa).
- AC-24: Membatalkan satu pembayaran pada tagihan `lunas` → status menjadi `sebagian` (atau `belum` bila itu satu-satunya pembayaran). Setelah semua pembayaran dibatalkan, `CancelInvoice` berhasil.
- AC-25: Pembayaran sudah `batal` → key `payment` `already_cancelled`; alasan kosong/spasi atau > 255 → key `reason` `reason_invalid`. Tidak ada perubahan. Instance basi (dibatalkan lewat instance lain) → `already_cancelled`.
- AC-26 (audit): satu `CancelPayment` menghasilkan tepat satu activity: log `keuangan`, event `updated`, subject pembayaran, causer user, `old.status` `diterima`, `attributes.status` `batal`, `reason`, `invoice_status` lama/baru.
- AC-27: Tidak ada jalur aplikasi yang menghapus pembayaran: `PaymentPolicy::delete`/`deleteAny` false untuk `bendahara`; `PaymentResource` dan relation manager tidak punya aksi Delete/Edit.
- AC-28 (invarian): setelah urutan bayar 300.000, bayar 400.000, batalkan yang pertama, bayar 500.000, batalkan yang terakhir → untuk tiap item `paid_amount = sum(alokasi pembayaran diterima)` dan `<= amount`; `paid_total = sum(payments diterima) = sum(paid_amount)`; status sesuai `syncStatus()`.

**Cakupan jenjang**
- AC-29: Bendahara berjenjang SLTP: daftar `/admin/payments` hanya memuat pembayaran tagihan santri SLTP (termasuk santri SLTP yang di-soft-delete); detail pembayaran santri SLTA → 404; `/admin/receipts/{id}` dan `/admin/payment-proofs/{id}` untuk pembayaran santri SLTA → 404. Bendahara tanpa `unit_user` melihat semua.

**Panel & otorisasi**
- AC-30: `RolePermissionSeeder` membuat permission resource `Payment` dan `cancel:payment`; dataset `bendahara` persis bertambah `view-any:payment`, `view:payment`, `create:payment`, `cancel:payment`; dataset peran lain tidak berubah; test "every permission that a generated policy checks" lulus dengan `PaymentPolicy`.
- AC-31: `bendahara` dan `super-admin` membuka `/admin/payments` dan `/admin/payments/{id}` (200). `admin-sekolah`, `kesantrian`, `musyrif`, `admin-konten`, `admin-psb`, `ustadz-tahfidz`, `petugas-pos` → 403.
- AC-32: Modul `keuangan` mati → `/admin/payments`, `/admin/receipts/{id}`, `/admin/payment-proofs/{id}` 403, menu Pembayaran tidak tampil.
- AC-33: Aksi "Catat pembayaran" di `ViewInvoice` (Livewire `callAction`) dengan nominal 500.000, tanggal hari ini, tunai → pembayaran tercatat, notifikasi `success` memuat nomor kuitansi; isian bawaan `amount` = sisa tagihan (1.200.000). Aksi juga ada di baris daftar tagihan.
- AC-34: Aksi "Catat pembayaran" tersembunyi untuk tagihan `lunas` dan `batal`; tidak terlihat/tidak bisa dijalankan oleh user dengan `view-any:invoice` dan `view:invoice` tetapi tanpa `create:payment`.
- AC-35: Dari panel, nominal melebihi sisa → error pada field `amount` dan modal tetap terbuka, tanpa data. Tagihan dibatalkan di tempat lain sebelum submit → notifikasi `danger` (`invoice_cancelled`), tanpa data.
- AC-36: Dari panel, metode transfer dengan unggahan bukti (file fake) → bukti tersimpan di disk `private`, `proof_path` terisi. Field bukti tidak tampil saat metode tunai.
- AC-37: Detail tagihan menampilkan kolom "Terbayar" per item dan relation manager pembayaran berisi pembayaran `diterima` dan `batal` dengan badge "Diterima" / "Batal"; tanpa pembayaran → "Belum ada pembayaran untuk tagihan ini."
- AC-38: Aksi "Batalkan pembayaran" (relation manager, daftar, dan detail pembayaran) dengan alasan → status `batal` dan notifikasi `success`; tersembunyi untuk pembayaran `batal`; tidak bisa dijalankan tanpa `cancel:payment`; tanpa alasan → error field `reason`.
- AC-39: Daftar pembayaran menampilkan uang `Rp 500.000`, tanggal `15 Okt 2026`, metode "Tunai", badge status dari `lang/id`; filter status, metode, jenjang, dan rentang tanggal menyaring data; daftar kosong menampilkan "Belum ada pembayaran.". Detail pembayaran menampilkan alokasi per rincian tagihan dan terbilang.

**Kuitansi & bukti transfer**
- AC-40: `GET /admin/receipts/{id}` oleh `bendahara` untuk pembayaran 500.000 → 200, HTML memuat nama sekolah (`settings` `school.name`), "Kuitansi", `KW-202610-00001`, `15 Okt 2026`, NIS dan nama santri, nomor tagihan, "Oktober 2026", deskripsi dan jumlah tiap alokasi ("Makan Oktober 2026" `Rp 400.000`, "Asrama Oktober 2026" `Rp 100.000`), total `Rp 500.000`, "Lima ratus ribu rupiah", "Tunai", nama petugas. HTML tidak memuat `@vite`, `/build/`, maupun URL `http(s)://` eksternal selain domain aplikasi.
- AC-41: "Diterima dari" berisi nama wali yang menjadi kontak tagihan santri; tanpa kontak tagihan → nama santri. Alamat/telepon sekolah tampil hanya bila key `settings` ada.
- AC-42: Kuitansi pembayaran `batal` tetap bisa dibuka dan memuat "DIBATALKAN" beserta alasannya.
- AC-43: Akses kuitansi: tamu → redirect ke login panel; peran tanpa `view:payment` (mis. `admin-sekolah`) → 403; id tidak ada → 404.
- AC-44: `GET /admin/payment-proofs/{id}` oleh `bendahara` → 200, isi sama dengan file, `Content-Disposition` attachment dengan nama `bukti-transfer-KW-202610-00001.jpg`. Tamu → redirect login; tanpa `view:payment` → 403; pembayaran tanpa bukti atau file hilang dari disk → 404.
- AC-45: `Payment::amountInWords()` (dataset): 500.000 → "Lima ratus ribu rupiah"; 1.250.000 → "Satu juta dua ratus lima puluh ribu rupiah"; 1.001.500 → "Satu juta seribu lima ratus rupiah".

**Lain-lain**
- AC-46: `EditUser` menyembunyikan tombol Hapus untuk user yang tercatat sebagai `received_by` atau `cancelled_by` pembayaran (dicek tanpa cakupan jenjang).
- AC-47: Semua teks baru ada di `lang/id/pondok.php`: tidak ada key mentah `pondok.payment.*` atau `pondok.receipt.*` di daftar/detail pembayaran, detail tagihan, modal "Catat pembayaran" dan "Batalkan pembayaran", serta halaman kuitansi.

## Rencana test

| AC | File | Nama test (usulan) |
|---|---|---|
| 1–4 | `tests/Feature/Database/KeuanganConstraintsTest.php` (tambah) | `AC-1: rejects a duplicate receipt number`, `AC-2: rejects a duplicate allocation per item`, `AC-3: restricts deleting referenced payment rows`, `AC-4: stores payment money as integers with received default` |
| 5, 8, 10 | `tests/Feature/Actions/Keuangan/AllocatePaymentToItemsTest.php` | `AC-5: allocates to the lowest priority number first`, `AC-8: breaks priority ties by item id and skips settled items`, `AC-10: refuses to allocate more than the items owe` |
| 6–7, 9, 11–21 | `tests/Feature/Actions/Keuangan/RecordPaymentTest.php` | `AC-6: allocates instalments by fee type priority`, `AC-7: ignores item creation order`, `AC-9: reads priority at payment time`, `AC-11: settles an invoice with a full payment`, `AC-12: marks partial payment and locks the invoice`, `AC-13: rejects paying more than the remaining amount`, `AC-14: rejects paid or cancelled invoices` (dataset), `AC-15: validates payment input` (dataset), `AC-16: validates against the locked invoice row`, `AC-17: stores transfer proof on the private disk` (dataset penolakan), `AC-18: removes the stored proof when recording fails`, `AC-19: numbers receipts per recording month`, `AC-20: retries and stops on receipt number clashes`, `AC-21: logs exactly one activity per payment` |
| 22–26, 28 | `tests/Feature/Actions/Keuangan/CancelPaymentTest.php` | `AC-22: cancels a payment and reverses its allocations`, `AC-23: keeps other payments when cancelling one in the middle`, `AC-24: recomputes invoice status after cancellation`, `AC-25: rejects invalid cancellations` (dataset), `AC-26: logs exactly one activity per cancellation`, `AC-28: keeps paid totals equal to active allocations` |
| 27, 31–32 | `tests/Feature/Filament/KeuanganAccessTest.php` (tambah) | `AC-27: offers no way to delete payments`, `AC-31: lets bendahara open payments`, `AC-31: forbids payments to other roles` (dataset), `AC-32: hides payments when the module is disabled` |
| 29, 38–39 | `tests/Feature/Filament/Resources/Payments/PaymentResourceTest.php` | `AC-29: scopes payments to the user's units`, `AC-38: cancels a payment from the panel`, `AC-39: formats and filters payments` |
| 33–37 | `tests/Feature/Filament/Resources/Invoices/RecordPaymentActionTest.php` | `AC-33: records a payment from the invoice page`, `AC-34: hides record payment when not allowed` (dataset), `AC-35: shows payment errors on the form`, `AC-36: uploads transfer proof from the panel`, `AC-37: shows paid amounts and payments on the invoice` |
| 40–45 | `tests/Feature/Http/Keuangan/PaymentReceiptTest.php`, `PaymentProofDownloadTest.php`, `tests/Feature/Models/PaymentTest.php` | `AC-40: prints a receipt without external assets`, `AC-41: names the billing contact as payer`, `AC-42: marks cancelled receipts`, `AC-43: guards the receipt page` (dataset), `AC-44: downloads proof only for authorised users` (dataset), `AC-45: spells the amount in Indonesian` (dataset) |
| 30 | `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` | ubah dataset `bendahara`; tambah `AC-30: creates payment permissions and keeps bendahara from deleting payments` |
| 46 | test EditUser yang ada | `AC-46: hides delete for a user who received or cancelled payments` |
| 47 | `PaymentResourceTest.php`, `PaymentReceiptTest.php` | `AC-47: has no raw lang keys` |

Helper fixture baru di `tests/Pest.php` (pola `tagihan*`): `pembayaranInvoice(object $f, ?Student $student = null): Invoice` (santri mukim + generator Oktober), `pembayaranRecord(Invoice $invoice, int $amount, array $overrides = [], ?User $by = null): Payment` (lewat container), `pembayaranAssertRejected(Closure, string $reason, ?string $field)` (pola `tagihanAssertRejected` untuk `pondok.payment.errors.*`).

## Urutan implementasi

1. Migration + model + factory `payments` (kolom status/pembatalan, index, scope jenjang, konstanta, `amountInWords`, activitylog jaring pengaman) dan `payment_allocations`; relasi baru di `Invoice`, `InvoiceItem`, `User` — AC-1–4, 45.
2. `AllocatePaymentToItems` — AC-5, 8, 10.
3. `GenerateReceiptNumber` — bagian AC-19.
4. `RecordPayment` jalur dasar (validasi, kunci, alokasi, status, log) — AC-6–7, 9, 11–16, 21.
5. Bukti transfer di disk `private` + pembersihan + percobaan ulang nomor — AC-17–20.
6. `CancelPayment` — AC-22–26, 28.
7. Permission seeder + `PaymentPolicy` + dataset seeder test — AC-30.
8. `PaymentResource` daftar/detail + cakupan jenjang + aksi batalkan + geser `navigationSort` keuangan — AC-27, 29, 31–32, 38–39.
9. `InvoiceResource`: aksi "Catat pembayaran", kolom "Terbayar", `PaymentsRelationManager` — AC-33–37.
10. Route panel + controller kuitansi dan unduh bukti + view cetak — AC-40–44.
11. `EditUser` guard — AC-46. Teks `lang/id` lengkap — AC-47.
12. Dokumen: `docs/erd.md` (entitas `payments` + kolom `status`, `cancelled_at`, `cancelled_by`, `cancel_reason`; baris constraint `payments` unique `receipt_no`, index; `payment_allocations` unique), `docs/design-guide.md` §4 (baris "Pembayaran | diterima | `success`").
13. Pint, checklist design guide §0, cek visual halaman cetak di desktop dan pratinjau cetak A5 (atau laporkan belum dicek).

## Risiko & pertanyaan terbuka

Setiap butir punya default yang dipakai bila user tidak memutuskan lain.

1. **Kolom status pembatalan di `payments` (penyimpangan dari ERD).** ERD tidak punya status di `payments`, padahal data keuangan tidak boleh dihapus. *Default:* tambah `status` (`diterima` | `batal`, bawaan `diterima`), `cancelled_at`, `cancelled_by`, `cancel_reason`; ERD diperbarui. Alternatif yang ditolak: baris pembayaran negatif/pembalik (kolom uang `unsigned`, kuitansi minus membingungkan bendahara dan wali).
2. **Alokasi saat pembatalan.** *Default:* alokasi pembayaran yang dibatalkan tetap tersimpan tetapi tidak berlaku; pembayaran lain **tidak** dialokasikan ulang (kuitansi yang sudah dicetak tetap benar). Akibatnya setelah pembatalan di tengah, item prioritas tinggi bisa belum terbayar sementara item prioritas rendah sudah terbayar; pembayaran berikutnya mengisi item prioritas tertinggi lebih dulu (AC-23). Pembatalan boleh untuk pembayaran mana pun, bukan hanya yang terakhir.
3. **Format nomor kuitansi (PRD §7 masih terbuka).** *Default:* `KW-YYYYMM-NNNNN`, urut per bulan pencatatan (bukan bulan `paid_on`), satu urutan untuk semua jenjang dan metode, pembayaran batal tetap memakai nomornya. Bila lembaga pengguna punya format sendiri, cukup ubah `GenerateReceiptNumber`.
4. **Bukti transfer.** *Default:* opsional untuk transfer, ditolak untuk tunai, jpg/png/pdf maks 2 MB, diunduh sebagai lampiran (bukan ditampilkan inline). Tidak ada verifikasi transfer terpisah (bendahara mencatat setelah mengecek mutasi).
5. **Tanggal bayar.** *Default:* boleh mundur tanpa batas bawah, tidak boleh masa depan. Kuitansi menampilkan `paid_on`; waktu pencatatan ada di detail pembayaran dan activity log.
6. **Prioritas saat bayar, bukan snapshot.** *Default:* `fee_types.priority` dibaca saat pembayaran (AC-9). Mengubah prioritas tidak mengubah alokasi yang sudah ada.
7. **Izin kuitansi dan bukti.** *Default:* cukup `view:payment` + cakupan jenjang; tidak ada permission khusus cetak/unduh. Bukti transfer bukan dokumen rahasia setingkat KK/NIK.
8. **Kuitansi HTML, bukan PDF.** *Default:* halaman cetak HTML (A5, tanpa warna); PDF lewat Service class butuh dependency baru dan persetujuan user (bisa jadi fitur terpisah). Terbilang memakai `Number::spell` (ext-intl). Keluaran ICU antarversi bisa sedikit berbeda (mis. "seribu"); AC-45 menjaga kasus umum. Bila ICU hosting berbeda, ganti dengan helper terbilang sendiri tanpa dependency.
9. **Satu pembayaran = satu tagihan; tanpa kelebihan bayar.** *Default:* nominal maksimal sisa tagihan. Pembayaran beberapa tagihan sekaligus (mis. tunggakan 3 bulan dalam satu kuitansi) diusulkan sebagai entri baru (lihat laporan), bukan bagian F007.
10. **`received_by` nullable.** *Default:* nullable di skema untuk callback gateway F020; F007 selalu mengisinya.
11. **Konkurensi.** Kunci baris tagihan (`lockForUpdate`) mencegah dua kasir melebihi sisa tagihan di MySQL/Postgres; SQLite menyerialkan penulisan. Uji konkurensi nyata tidak bisa di SQLite; AC-16/AC-25 menguji validasi ulang pada baris terkunci dengan instance basi.
12. **`FileUpload::storeFiles(false)`** di Filament 5 meneruskan `TemporaryUploadedFile` ke Action. Implementer memastikan perilakunya lewat `search-docs`; bila tidak memungkinkan, alternatif: FileUpload ke folder sementara di disk `private` dan Action memindahkan file (perilaku AC-17/18 tetap).
13. **Satu activity per operasi.** *Default:* perubahan status tagihan akibat pembayaran dicatat di properti `invoice_status` log pembayaran, bukan sebagai log terpisah pada subject tagihan (pola F006 satu log per operasi). Riwayat tagihan dicari lewat `properties.invoice_id`.

## Riwayat revisi

- r1 (DRAFT): versi awal oleh product-manager.

## Review #1 — 2026-10-15

Verdict: APPROVED

Semua `acceptance` F007 dan kebutuhan PRD 3.5 tercakup. Alokasi prioritas, status tagihan, dan pembatalan tanpa hapus terpetakan ke AC. Default PM 1–13 diterima (kolom status/cancelled_* di luar ERD sudah disetujui, ERD diperbarui di langkah 12). Urutan kunci tagihan lalu pembayaran konsisten dengan `CancelInvoice`. Pembayaran batal mengembalikan `paid_total` ke 0, sehingga `Invoice::isCancellable()` dan `syncStatus()` F006 tetap benar tanpa mengubah kode F006.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Route kuitansi/bukti | Urutan pemeriksaan "modul mati → 403 lalu binding → 404" tidak akan terjadi apa adanya: route model binding (`SubstituteBindings`) jalan sebelum controller. Id yang tidak ada atau di luar cakupan akan 404 walau modul mati. AC-32 memakai id valid sehingga lolos, tetapi teks rencana menyesatkan. | Taruh cek modul `keuangan` sebagai middleware route (sebelum binding), atau ubah teks rencana agar urutannya sesuai kenyataan. |
| 2 | minor | Kuitansi / PaymentResource | Kolom `invoice.student.*`, filter `whereHas('invoice.student')`, dan "Diterima dari" mengasumsikan `invoices.student_id` terisi. `Invoice` boleh milik pendaftar (`student_id` null, F010) dan terlihat oleh super-admin/bendahara tanpa jenjang. Akan null-deref bila ada pembayaran tagihan pendaftar. | Buat view kuitansi dan kolom/infolist null-safe (fallback ke `applicant`/"-"). Tambah satu assert pada AC-40/41 bila murah. |
| 3 | minor | Kuitansi, "Diterima dari" (AC-41) | Bila ada lebih dari satu wali berstatus `is_billing_contact`, wali mana yang dipakai tidak ditentukan (hasil tidak deterministik). | Tentukan: wali kontak tagihan dengan `id` pivot/wali terkecil, dan uji. |
| 4 | minor | `amountInWords` (AC-45, Risiko #8) | Terbilang pada dokumen keuangan bergantung pada ICU hosting (`Number::spell`), padahal target shared hosting cPanel yang versi ICU-nya beragam. Dataset AC-45 bisa gagal atau, lebih buruk, lolos di dev tetapi salah di produksi. | Pertimbangkan helper terbilang sendiri yang deterministik (kecil, tanpa dependency). Bila tetap `Number::spell`, jangan ubah AC-45 agar longgar. |
| 5 | minor | `RecordPayment` | Tidak ada perlindungan kirim ganda (double submit). Dua submit nominal sama saat sisa masih cukup menghasilkan dua pembayaran sah. | Cukup dicatat sebagai keterbatasan; Livewire menutup modal saat submit. Pertimbangkan nonaktifkan tombol saat loading (`->extraAttributes`/`wire:loading`). |
| 6 | minor | `CancelPayment` / permission | Pembatalan boleh untuk pembayaran mana pun tanpa batas waktu. Setelah F008 (laporan kas harian) membatalkan pembayaran lama mengubah angka periode yang sudah dilaporkan. | Terima untuk F007 (audit log lengkap). Catat di notes F008 bahwa laporan kas harus memakai `paid_on` dan status saat dibaca, dan pertimbangkan permission pembatalan terpisah dari `create` (sudah: `cancel:payment`). |
| 7 | minor | Rencana test | Cakupan jenjang diuji di `PaymentResource` dan route, tetapi tidak untuk `PaymentsRelationManager` dan aksi "Catat pembayaran" pada tagihan jenjang lain. Pergeseran `navigationSort` resource lain bisa menyentuh test navigasi yang ada. | Tambah satu assert: user SLTP tidak bisa membuka `ViewInvoice` tagihan SLTA untuk mencatat pembayaran (404). Jalankan test Keuangan yang ada setelah menggeser `navigationSort`. |
| 8 | minor | Model data | `paid_on` dibatasi "tidak masa depan" memakai `Asia/Jakarta` (cocok dengan `config/app.php` timezone), tetapi `DatePicker::maxDate(today())` dan Action harus memakai zona yang sama. | Pakai `today()`/`now()` aplikasi di kedua tempat agar konsisten; AC-15 sudah menguji batas 2026-10-16. |

Catatan kesesuaian: kolom uang `unsignedBigInteger`, semua FK `restrictOnDelete`, tanpa soft delete, bukti di disk `private` lewat route yang dicek modul/cakupan/permission, satu log manual per operasi, retry nomor di luar closure transaksi (aman Postgres), dan query portabel (`like` + `max` pada string lebar tetap, enum, `lockForUpdate` no-op di SQLite) sesuai konvensi dan pola F006. Tidak ada kebutuhan Redis/Node/dependency baru.
