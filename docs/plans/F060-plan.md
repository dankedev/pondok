# F060 keuangan-import-tagihan-awal — Import saldo tunggakan awal dari CSV

Issue: #29
Status rencana: APPROVED (review #1: 0 blocker, 0 major, 6 minor)

## Ringkasan & tujuan
Bendahara memuat saldo tunggakan awal (sisa yang BELUM dibayar per santri per jenis biaya) saat lembaga mulai memakai Pondok. Satu baris CSV = satu santri + satu jenis biaya + satu nominal. Pakai dasar F058/F059 (`CsvImporter`, `HasCsvImportActions`). Hasilnya tagihan biasa berstatus `belum`, `paid_total` 0, sehingga pembayaran lewat `RecordPayment` dan pembatalan lewat `CancelInvoice` bekerja tanpa perubahan.

## Ruang lingkup
- Termasuk: aksi Import + Unduh template di halaman Tagihan (`ListInvoices`); `InvoiceBalanceImporter`; Action `ImportOpeningBalanceRow`; permission `import:invoice`; kelompok tagihan khusus `SALDO_AWAL`; pengecualian kelompok itu di `GenerateMonthlyInvoices`; pencadangan nama kelompok di `SaveFeeType`; catatan di `docs/erd.md`.
- Tidak termasuk (eksplisit, menggantikan kriteria usulan di issue #29 sesuai keputusan user 19 Okt 2026): rincian per bulan, kolom periode, jatuh tempo per baris, kolom "sudah dibayar", riwayat pembayaran lama; tidak ada `payments`/`payment_allocations` yang dibuat; `.xlsx`; kolom catatan; tarif/keringanan (nominal dipakai apa adanya); impor untuk pendaftar (`applicant_id`); mengubah atau menghapus tagihan lewat file; ringkasan audit tambahan per file (tabel `imports` sudah mencatat pengguna dan jumlah baris).

## Keputusan
1. **Kelompok khusus.** Konstanta `Invoice::GROUP_OPENING_BALANCE = 'SALDO_AWAL'` (bukan milik `fee_types`). Tagihan saldo awal selalu `billing_group = SALDO_AWAL` apa pun jenis biayanya, jadi tidak pernah bercampur dengan tagihan SPP/asrama dan tidak bentrok dengan unique (`student_id`, `period`, `billing_group`, `active_marker`). Tanpa migration.
2. **Generator bulanan.** `GenerateMonthlyInvoices::processChunk` mengecualikan `billing_group = SALDO_AWAL` dari query `$existing` (satu `where`). Tanpa ini item SPP di tagihan saldo awal dianggap `billedIn` dan SPP bulan itu tidak pernah dibuat. Dengan pengecualian, SPP bulan berjalan tetap ditagih penuh dan saldo awal tidak dianggap tagihan bulan itu. `SaveFeeType` menolak `billing_group = SALDO_AWAL` (dicadangkan) agar tidak ada jenis biaya yang masuk kelompok ini lewat generator atau PSB.
3. **Periode dan jatuh tempo (tanpa kolom CSV).** `period` = tanggal 1 bulan saat import berjalan (zona `Asia/Jakarta`, dihitung saat baris diproses). `due_on` = hari terakhir bulan sebelumnya, jadi langsung terhitung terlambat (`isOverdue`) dan tampil sebagai tunggakan. `academic_year_id` = tahun ajaran aktif; tidak ada → baris gagal. `generated_by` = pengimpor.
4. **Idempotensi lintas bulan.** Kunci (santri, jenis biaya): bila ada tagihan aktif (bukan `batal`) berkelompok `SALDO_AWAL` milik santri itu yang memuat jenis biaya itu, di periode mana pun, baris GAGAL dengan pesan "saldo awal jenis biaya ini sudah pernah diimpor" (tidak menimpa, tidak menambah). Tagihan `batal` tidak dihitung, jadi boleh diimpor ulang (pola generator). Kegagalan memakai `RowImportFailedException` agar muncul di file baris gagal.
5. **Satu tagihan per santri per import.** Baris pertama santri membuat tagihan `SALDO_AWAL` periode ini; baris berikutnya (jenis biaya lain) menambah item ke tagihan aktif itu bila `paid_total = 0` (lalu `recalculateTotal`). Bila sudah ada pembayaran, baris gagal ("tagihan saldo awal bulan ini sudah ada pembayaran, impor bulan depan"). Item: `base_amount = amount = nominal`, `discount_amount = 0`, `rate_source = manual`, `fee_override_id` null, `description` dari lang `pondok.invoice.opening_balance_item` ("Saldo awal {fee_type}"). Satu baris = satu transaksi.
6. **Nomor tagihan.** `GenerateInvoiceNumber::handle($period)` di dalam transaksi; bentrok nomor (`UniqueConstraintViolationException` dengan tagihan aktif kelompok belum ada) dicoba ulang maksimal 3 kali dengan membaca ulang urutan; setelah itu baris gagal. Bila bentrok karena tagihan aktif kelompok sudah dibuat proses lain, ulang sebagai penambahan item.
7. **Jenis biaya.** Cocok `fee_types.code` (huruf besar, trim) dan `is_active`. Semua frekuensi dan `applies_to` boleh (tunggakan adalah fakta, bukan hasil aturan tarif; ini termasuk uang pangkal yang masih tertunggak). Tarif dan keringanan tidak dipakai.
8. **Santri.** `Student::query()->where('nis')` (UnitScope otomatis, sama dengan F059). NIS tidak ada atau di luar cakupan jenjang = pesan sama. Santri nonaktif (lulus/keluar) boleh (tunggakan alumni tetap piutang); soft-deleted tidak ketemu.
9. **Nominal.** Hanya digit `^[1-9][0-9]{0,11}$` setelah trim; menolak 0, negatif, desimal, pemisah ribuan titik/koma, spasi, dan "Rp" (pesan menjelaskan: tulis angka rupiah tanpa titik, mis. `1500000`). Disimpan sebagai integer. Duplikat NIS + kode di satu file: baris kedua gagal lewat keputusan 4 (tagihan baris pertama sudah ada).
10. **Audit.** Satu `activity('keuangan')->event('imported')` per tagihan yang BARU dibuat (subject invoice; properti: `source = opening_balance`, `items` = kode jenis biaya, `total`), dan penambahan item mencatat satu log serupa dengan `event('imported')`. Log otomatis model dimatikan (`withoutLogs`) agar tepat satu log. Tanpa NIS/nama di properti.

## Model data
Tanpa migration, kolom, atau index baru. Tabel `invoices` dan `invoice_items` sudah memuat semua yang dibutuhkan. Uang `unsignedBigInteger` rupiah. Data keuangan tidak dihapus.

## Action class
`app/Actions/Keuangan/ImportOpeningBalanceRow` — input array baris (`nis`, `fee_type`, `amount`), output `Invoice`. Urutan: (1) cari santri (kep. 8), (2) cari jenis biaya aktif (kep. 7), (3) validasi nominal (kep. 9), (4) tahun ajaran aktif (kep. 3), (5) cek idempotensi (kep. 4), (6) transaksi: tagihan baru atau tambah item (kep. 5-6), (7) log (kep. 10). Pesan gagal Indonesia di `pondok.invoice.import.errors.*`. Tanpa efek samping lain; tidak memanggil `ResolveFeeRate`/`ApplyFeeOverride`.

## UI (Filament)
- `ListInvoices::getHeaderActions()`: `...csvImportActions(InvoiceBalanceImporter::class, 'template-saldo-awal.csv', <deskripsi>, fn () => auth()->user()->can('import', Invoice::class))` lalu `generateAction()`. Halaman memakai trait `HasCsvImportActions`.
- `InvoiceBalanceImporter extends CsvImporter`, kolom `nis`*, `fee_type`* (Kode jenis biaya), `amount`* (Nominal tunggakan, rupiah); contoh fiktif `2026001`, `SPP`, `1500000`. `saveRecord()` memanggil Action; notifikasi "{n} saldo awal diimpor, {m} baris gagal".
- Teks di `lang/id/pondok.php` (`invoice.import`, `invoice.opening_balance_item`). Modal menjelaskan: satu baris per santri per jenis biaya, nominal = sisa belum dibayar, tulis angka tanpa titik, baris yang sudah pernah diimpor ditolak, tagihan bertanda kelompok SALDO_AWAL. Design-guide: aksi dan notifikasi, §0; tanpa Blade baru.

## Permission & cakupan
- `import:invoice`: ubah `EXTRA_RESOURCE_ACTIONS['Invoice']` menjadi `['generate', 'cancel', 'import']`, `InvoicePolicy::import`, dan tambah `import` ke daftar Invoice milik `bendahara`. Peran lain tidak (termasuk `admin-sekolah`).
- Cakupan jenjang lewat UnitScope pada pencarian santri; `Invoice` sendiri ikut global scope. Saklar `modules.keuangan.enabled`: halaman tagihan sudah mengikuti saklar, tidak ada route baru.

## Dampak ke konsumen lain
- Laporan keuangan per periode (`SummarizeInvoices`, ekspor CSV): saldo awal muncul di baris periode bulan import (ditagih, belum terbayar, terlambat) dan bisa dipisah lewat filter kelompok `SALDO_AWAL` yang sudah ada. Rasio koleksi bulan import turun; didokumentasikan, tanpa kode khusus.
- `ListTopArrears`: tunggakan awal ikut (due_on lewat), `oldest_period` = bulan import. Dashboard bulan berjalan memuatnya di bulan import saja.
- PSB: konversi pendaftar hanya memindah tagihan pendaftar (tidak menyentuh `SALDO_AWAL`); `CreatesApplicantInvoices` tidak berubah (kelompok dicadangkan, kep. 2). `RecordPayment`/`CancelInvoice`: tidak berubah.

## Kriteria penerimaan (bisa dites)
- AC-1: `bendahara`/`super-admin` melihat aksi Import dan Unduh template di `ListInvoices`; `admin-sekolah`, `kesantrian`, `admin-psb` tidak melihat dan tidak bisa memanggil. Permission `import:invoice` hanya milik `super-admin` dan `bendahara` (test seeder persis diperbarui; peran lain tidak berubah).
- AC-2: Template CSV ber-BOM UTF-8 memuat judul Indonesia untuk tiga kolom dan satu baris contoh fiktif yang sukses bila diimpor dengan data cocok; `ListStudents`/`ManageRoomAssignments` tetap berfungsi.
- AC-3: Baris valid membuat satu tagihan: `billing_group = SALDO_AWAL`, status `belum`, `paid_total` 0, `total = nominal`, `period` = tanggal 1 bulan import, `due_on` = akhir bulan lalu (`isOverdue` benar), tahun ajaran aktif, `generated_by` pengimpor, nomor sesuai `GenerateInvoiceNumber`; satu `invoice_items` dengan `base_amount = amount = nominal`, `discount_amount` 0, `rate_source manual`; tidak ada `payments`/`payment_allocations`.
- AC-4: Dua baris santri sama dengan jenis biaya berbeda (file sama) → satu tagihan dengan dua item dan total jumlahnya; bila tagihan itu sudah punya `paid_total > 0` baris tambahan gagal dan tagihan tak berubah.
- AC-5: Idempotent: mengimpor file sama dua kali tidak menambah tagihan/item (baris kedua gagal "sudah pernah diimpor"); import ulang di bulan berikutnya (waktu diundur) juga gagal; setelah tagihan lama `batal`, baris yang sama berhasil membuat tagihan baru; duplikat NIS + kode dalam satu file: baris kedua gagal.
- AC-6: Nominal tidak valid (0, negatif, `1.500.000`, `1,5`, `Rp1500000`, huruf, kosong, >12 digit) → gagal dengan alasan Indonesia, tanpa tagihan.
- AC-7: NIS tidak ada, kode jenis biaya tidak ada, jenis biaya nonaktif, atau tidak ada tahun ajaran aktif → baris gagal berpesan jelas; baris valid lain di file sama tetap masuk; notifikasi "n berhasil, m gagal".
- AC-8: Santri nonaktif (lulus) berhasil; soft-deleted gagal. User ber-`unit_user` terbatas: NIS jenjang lain gagal ("tidak ditemukan"), dalam cakupan sukses; tanpa `unit_user` melihat semua.
- AC-9: Generator bulanan: setelah saldo awal SPP periode berjalan diimpor, `GenerateMonthlyInvoices` untuk bulan yang sama tetap membuat tagihan SPP bulan itu (tidak dilewati), tagihan `SALDO_AWAL` tidak berubah, dan generator dijalankan ulang tetap idempotent.
- AC-10: `SaveFeeType` menolak `billing_group` `SALDO_AWAL` (juga bentuk huruf kecil/spasi yang dinormalisasi) dengan pesan Indonesia; kelompok lain tak terpengaruh.
- AC-11: Tunggakan awal terlihat di konsumen: `ListTopArrears` memuat santri dengan jumlah saldo awal; `SummarizeInvoices` memuatnya pada periode import dan filter `billing_group = SALDO_AWAL` memisahkannya; pembayaran lewat `RecordPayment` pada tagihan itu mengubah status ke `sebagian`/`lunas` dan alokasi ke item.
- AC-12: Audit dan keamanan: tiap tagihan baru punya tepat satu log `keuangan` event `imported` (tanpa NIS/nama di properti; penambahan item juga satu log); file baris gagal tidak berisi formula aktif (`=`, `+`, `-`, `@` diawali `'`); notifikasi database terkirim pada antrean `sync` maupun `database`; import berjalan benar lewat `queue:work --stop-when-empty` dengan nomor tagihan unik.

## Rencana test
- `tests/Feature/Filament/Invoices/ImportOpeningBalanceTest.php` (pola `ImportRoomAssignmentsTest`): AC-1, 2, 3, 4, 5, 6, 7, 8, 12.
- `tests/Feature/Actions/Keuangan/ImportOpeningBalanceRowTest.php`: aturan nominal/pencocokan, nomor tagihan bentrok-ulang (AC-3, 4, 6).
- `tests/Feature/Actions/Keuangan/OpeningBalanceInteractionTest.php`: AC-9, 10, 11.
- Perbarui `RolePermissionSeederTest` (AC-1). Test F058/F059 dan generator yang ada tidak diubah dan tetap hijau.

## Urutan implementasi
Paket 1 (data + Action; test: `ImportOpeningBalanceRowTest`, `OpeningBalanceInteractionTest`, seeder, `GenerateMonthlyInvoices*`, `SaveFeeTypeTest`):
1. Konstanta `Invoice::GROUP_OPENING_BALANCE`; pengecualian di generator; cadangan di `SaveFeeType` + lang.
2. Permission `import:invoice` (seeder, policy, bendahara).
3. `ImportOpeningBalanceRow` + lang error.
Paket 2 (Filament; test: `ImportOpeningBalanceTest`):
4. `InvoiceBalanceImporter`, aksi di `ListInvoices`, lang UI.
5. `docs/erd.md` (aturan tagihan awal di bagian Keuangan & Tagihan; unique tetap), `docs/prd.md` satu baris fitur impor; Pint; cek tampilan modal 360px/1024px/desktop.

## Keputusan terbuka (untuk user)
1. `period` = bulan import dan `due_on` = akhir bulan lalu (langsung terlambat). Rekomendasi: ya, supaya tunggakan langsung tampil di laporan tunggakan. Dampak: rasio koleksi bulan import turun (bisa disaring lewat kelompok `SALDO_AWAL`). Alternatif: `due_on` = tanggal import.
2. Baris yang sudah pernah diimpor GAGAL (bukan dilewati diam-diam), agar koreksi nominal dilakukan lewat `CancelInvoice` lalu impor ulang. Rekomendasi: ya.

## Risiko
- Menambah item ke tagihan saldo awal yang sudah ada pembayaran ditolak; kasus tepi, cukup pesan jelas.
- Import paralel pada santri sama: unique `active_marker` menjaga satu tagihan; bentrok ditangani retry (kep. 6).
- Menyimpang dari issue #29 (kriteria usulan awal): body issue perlu diperbarui sesuai bagian "Tidak termasuk".

## Riwayat revisi
Review #1 (APPROVED), minor diselesaikan saat implementasi: (1) teks modal dan ERD menegaskan saldo hanya untuk tunggakan sebelum generator pertama (SPP bulan berjalan tetap ditagih generator, jangan diimpor ganda); (2) period dihitung sekali per import (awal eksekusi) bukan per baris; (3) cek idempotensi diulang di dalam transaksi; (4) retry nomor tagihan boleh menduplikasi pola generator; (5) test AC-2 dan AC-12 tidak mengulang formula injection, notifikasi, dan queue yang sudah diuji F058/F059, cukup log audit per tagihan dan nomor unik; (6) feature_list description sudah diperbarui.
