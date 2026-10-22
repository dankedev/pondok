# F078 Label kelompok tagihan yang mudah dibaca

Issue: #72 · Jalur cepat · Modul: Keuangan

## Tujuan

Filter **Kelompok tagihan** di Laporan Keuangan dan daftar Tagihan menampilkan
kode mentah `billing_group` (`BULANAN`, `PSB_DAFTAR`, `SALDO_AWAL`). Bendahara
perlu label yang langsung terbaca, tanpa mengubah nilai yang disimpan.

## Pendekatan

- `billing_group` adalah kode bebas buatan admin (Jenis Biaya), jadi tidak ada
  tabel label. Kode umum (yang dipakai data demo dan kode cadangan
  `SALDO_AWAL`) diberi label di `lang/id/pondok.php` →
  `invoice.billing_groups.<KODE>`.
- Kode tanpa label tampil terbaca: garis bawah jadi spasi, huruf kecil dengan
  kapital awal (`PSB_KITAB` → "Psb kitab").
- Satu helper di model `Invoice`: `billingGroupLabel()` dan
  `billingGroupOptions()` (kode → label, urut label, mengikuti cakupan jenjang
  yang sudah berlaku pada query `Invoice`). Dua filter yang sebelumnya
  menyalin query yang sama memakai helper ini.
- Form dan tabel Jenis Biaya tetap menampilkan kode, karena di sana admin
  menetapkan kodenya.

## File yang diubah

- `app/Models/Invoice.php`
- `app/Filament/Pages/FinanceReport.php` (opsi filter)
- `app/Filament/Resources/Invoices/InvoiceResource.php` (filter, kolom, rincian)
- `lang/id/pondok.php`
- `tests/Feature/Filament/Pages/FinanceReportTest.php`

## Kriteria penerimaan

1. Opsi filter Kelompok tagihan di Laporan Keuangan dan daftar Tagihan memakai
   label; nilai opsi tetap kode `billing_group`.
2. Kode umum (`BULANAN`, `PSB_DAFTAR`, `PSB_TERIMA`, `DAFTAR_ULANG`,
   `SALDO_AWAL`) berlabel dari `lang/id`.
3. Kode tanpa label tampil terbaca.
4. Memilih opsi tetap menyaring laporan dengan kode yang benar.
5. Kolom dan rincian tagihan memakai label yang sama.
6. Tanpa migration dan tanpa perubahan data tersimpan.
