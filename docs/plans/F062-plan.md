# F062 keuangan-penandatangan-kuitansi — Penandatangan kuitansi terpisah dari petugas pencatat

Issue: #31
Status rencana: APPROVED
Jalur: penuh (migration pada tabel keuangan + Action yang mengubah data).

## Ringkasan & tujuan
Bendahara punya tim yang mencatat pembayaran, tetapi kuitansi ditandatangani basah oleh bendahara. Pengaturan Keuangan mendapat isian "Penandatangan kuitansi" (satu staff aktif, opsional). Nama dan jabatan dibekukan di `payments` saat pembayaran dicatat, sehingga cetak ulang selalu identik.

## Ruang lingkup
- Termasuk: setting `keuangan.receipt_signer_staff_id`, kolom snapshot `payments.signer_name` dan `signer_title`, perubahan `RecordPayment`, blok tanda tangan `receipt.blade.php`, section baru di halaman `FinanceNumbering`, activity log perubahan setting, teks `lang/id`, update `docs/erd.md`.
- Tidak termasuk: gambar tanda tangan/stempel, persetujuan digital, perubahan siapa yang boleh mencatat, permission baru, mengisi ulang kuitansi lama (kolom `null` = perilaku lama), tampilan penandatangan di daftar pembayaran.

## Model data
- Migration baru `add_signer_to_payments_table`: `signer_name` string(255) nullable, `signer_title` string(255) nullable. `down()` menghapus kedua kolom. Tanpa index, tanpa FK.
- Justifikasi snapshot di kolom, bukan FK `staff_id`: kuitansi harus tetap sama walau staff diganti nama, jabatan, atau dihapus (FK `restrictOnDelete` malah menghalangi hapus staff). Tidak ada kolom/JSON snapshot di `payments` yang bisa dipakai; dua kolom string adalah solusi paling sederhana (pola `issued_letters.snapshot.signer`, tetapi tabel itu punya JSON, `payments` tidak).
- Aturan data keuangan tetap: baris tidak dihapus, nominal tidak disentuh. `Payment::getActivitylogOptions` hanya melog `status`, jadi kolom baru tidak membuat log otomatis; `Payment::$fillable` ditambah dua kolom.
- Setting: key `keuangan.receipt_signer_staff_id` (JSON int atau null/tidak ada), lewat `Setting::set`. Tanpa tabel baru.

## Action class
- `app/Actions/Keuangan/RecordPayment` (diubah): di dalam transaksi `record()`, baca `Setting::get('keuangan.receipt_signer_staff_id')`; cari `Staff` dengan id itu. Bila ada dan `is_active`, isi `signer_name = staff.name` dan `signer_title = trim(staff.position)` (string kosong menjadi `null`); selain itu keduanya `null`. Properti activity `created` ditambah `signer_name` dan `signer_title`. Tidak ada parameter baru; semua jalur pencatat (panel, seeder demo, PSB) lewat Action ini, sehingga seragam. `Payment::create` hanya dipanggil di sini (sudah dicek).
- `app/Actions/Keuangan/UpdateReceiptSigner` (baru): `handle(?int $staffId, User $actor): void`. Validasi: id harus staff yang ada dan aktif (null = hapus pilihan), selain itu `ValidationException`. Bila nilai sama dengan setting sekarang: tidak menulis dan tidak melog. Bila beda: `Setting::set` + satu activity `keuangan`, event `receipt_signer_updated`, `causedBy($actor)`, properti `old` dan `new` masing-masing `{staff_id, name, position}` atau `null`. Satu Action dengan satu pemanggil nyata (halaman); dipisah agar tidak menumpuk log penomoran dan agar bisa dites tanpa UI.

## UI (Filament / Blade)
- `FinanceNumbering` (diubah): tambah `Section` "Penandatangan kuitansi" berisi `Select` `receipt_signer_staff_id` (opsi: staff aktif, label "Nama — Jabatan" memakai pola `issued_letter.signer_option`, searchable, nullable, helper: "Kosong: petugas pencatat yang menandatangani. Berlaku untuk pembayaran yang dicatat setelah disimpan; kuitansi lama tidak berubah."). `fillData` mengisi id hanya bila staff itu masih ada dan aktif (jika tidak, kosong). `persist` memanggil `UpdateReceiptSigner` dalam transaksi yang sama dengan penomoran. Judul halaman tetap (lihat pertanyaan terbuka).
- `receipt.blade.php`: bila `$payment->signer_name` terisi: blok tanda tangan mencetak label "Penandatangan", ruang tanda tangan, nama tebal, jabatan (bila ada), lalu baris kecil "Dicatat oleh: <receiver name atau ->". Bila kosong: blok lama (label "Petugas" + nama petugas), tanpa baris "Dicatat oleh". Pembayaran `batal` memakai aturan yang sama. Hanya CSS yang sudah ada (kelas `.signature`, `.printed`), tanpa elemen baru; ikuti design-guide bagian cetak/dokumen.
- `lang/id/pondok.php`: `numbering.finance.signer_*` (heading, deskripsi, label, helper, placeholder), `receipt.signer`, `receipt.recorded_by`, `payment.activity_*` bila perlu. Tidak ada teks hard-coded.

## Permission & cakupan
Tanpa permission baru: halaman memakai `view:finance-numbering` (bendahara, super-admin), dan `save()` sudah `abort_unless(canAccess(), 403)`. Saklar `modules.keuangan.enabled` sudah dicek halaman. `unit_user` tidak berlaku pada setting ini. Mencetak kuitansi tetap lewat policy `view` Payment.

## Kriteria penerimaan (bisa dites)
- AC-1: Setting kosong → `RecordPayment` menyimpan `signer_name` dan `signer_title` `null`; kuitansi mencetak blok lama (label "Petugas" + nama pencatat), tanpa teks "Dicatat oleh".
- AC-2: Setting menunjuk staff aktif ber-jabatan → pembayaran baru menyimpan `signer_name` dan `signer_title` sama dengan staff; kuitansi mencetak nama + jabatan penandatangan dan baris "Dicatat oleh: <nama pencatat>"; tabel `payments` lain (nominal, alokasi, `received_by`) tidak berubah.
- AC-3: Beku: setelah pembayaran dicatat, ganti setting ke staff lain, ubah nama/jabatan staff semula, lalu hapus staff semula → kuitansi pembayaran lama tetap mencetak nama dan jabatan awal; pembayaran baru memakai penandatangan baru.
- AC-4: Setting menunjuk staff yang kini nonaktif, atau id yang sudah tidak ada → pembayaran baru tercatat sukses dengan `signer_*` `null` (perilaku lama), tanpa error.
- AC-5: Staff tanpa jabatan (`position` kosong/spasi) → `signer_title` `null`; kuitansi mencetak nama saja tanpa baris jabatan kosong.
- AC-6: Pembayaran lama (kolom `null`) dan pembayaran `batal` tetap bisa dicetak; yang `batal` tetap menampilkan tanda "DIBATALKAN" dan memakai snapshot yang sama.
- AC-7: `UpdateReceiptSigner` dengan staff aktif menyimpan setting dan menulis tepat satu activity `keuangan` event `receipt_signer_updated` dengan `causer` = pengubah, `old` dan `new` (id, nama, jabatan), serta timestamp; mengubah ke `null` mencatat `new` = `null`.
- AC-8: `UpdateReceiptSigner` dengan nilai sama dengan setting sekarang tidak menulis log; dengan id staff nonaktif atau tidak ada → `ValidationException`, setting dan log tidak berubah.
- AC-9: Halaman `FinanceNumbering`: bendahara/super-admin melihat Select berisi hanya staff aktif, dapat memilih dan menyimpan (setting berubah, log tercatat) serta mengosongkan; peran tanpa `view:finance-numbering` mendapat 403 dan setting tidak berubah. Bila staff tersimpan sudah nonaktif, Select tampil kosong.
- AC-10: Menyimpan halaman dengan hanya mengubah format penomoran (penandatangan tidak berubah) tidak menulis log `receipt_signer_updated`; mengubah penandatangan tidak merusak format penomoran.
- AC-11: Pencatatan lewat panel (aksi Catat Pembayaran) oleh petugas non-bendahara dengan penandatangan terisi: `received_by` = petugas, `signer_*` = penandatangan; log `created` pembayaran memuat `signer_name` dan `signer_title`.

## Rencana test
- `tests/Feature/Actions/Keuangan/RecordPaymentSignerTest.php`: AC-1, AC-3 (sisi data), AC-4, AC-5.
- `tests/Feature/Actions/Keuangan/UpdateReceiptSignerTest.php`: AC-7, AC-8.
- `tests/Feature/Http/Keuangan/PaymentReceiptTest.php` (tambah): AC-1 (cetak), AC-2, AC-3 (cetak ulang), AC-5, AC-6.
- `tests/Feature/Filament/Pages/FinanceNumberingSignerTest.php` (cari file test FinanceNumbering yang ada; bila ada, tambahkan di sana): AC-9, AC-10.
- `tests/Feature/Filament/Resources/Invoices/RecordPaymentActionTest.php` (tambah satu test): AC-11.
- Test lama tidak diubah kecuali daftar kolom `payments` bila ada yang mengecek.

## Urutan implementasi
Paket 1 (data + Action): migration, `Payment::$fillable`, `RecordPayment`, `UpdateReceiptSigner`, update `docs/erd.md`. Hijau: `RecordPaymentSignerTest`, `UpdateReceiptSignerTest`, `RecordPaymentActionTest`.
Paket 2 (Filament + cetak): section di `FinanceNumbering`, `receipt.blade.php`, `lang/id`. Hijau: `FinanceNumberingSignerTest`, `PaymentReceiptTest`. Cek tampilan cetak A5.
Pint, lalu test terdampak; suite penuh sekali di akhir oleh sesi utama.

## Risiko & pertanyaan terbuka
- Pertanyaan 1: judul halaman "Penomoran Kuitansi & Tagihan" kini memuat isian penandatangan. Rekomendasi: judul tidak diubah (YAGNI, hindari ubah banyak test/teks); bila ingin, ganti nanti lewat issue kecil.
- Pertanyaan 2: kuitansi lama sebelum fitur ini tetap mencetak petugas pencatat (tidak diisi ulang). Rekomendasi: setuju, sesuai AC issue dan YAGNI.
- Risiko: staff mengganti jabatan di antara penyimpanan setting dan pembayaran dicatat memakai nilai terbaru saat pencatatan; ini sesuai "dibekukan saat dicatat".

## Riwayat revisi
(belum ada)

## Review #1 — 2026-10-20

Verdict: APPROVED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | AC-10 / `FinanceNumbering::fillData` | Bila staff tersimpan sudah nonaktif, Select tampil kosong. Menyimpan hanya perubahan format lalu diam-diam mengosongkan setting dan menulis log `receipt_signer_updated`, padahal pengguna tidak mengubah penandatangan. | Terima sebagai perilaku (setting basi memang tidak berlaku, AC-4), atau tambahkan satu assert di AC-10 agar perilakunya terdokumentasi. |
| 2 | minor | AC-8 | Urutan cek `UpdateReceiptSigner` ambigu bila nilai sama dengan setting tetapi staff kini nonaktif: no-op atau ValidationException. | Tetapkan di test: bandingkan nilai dulu (no-op), validasi sesudahnya. |
| 3 | minor | AC-3 | "Hapus staff" bergantung pada kemampuan menghapus staff. `Staff::signedLetters` menahan hapus bila ada surat, dan `Staff` tidak punya soft delete. | Pakai `$staff->delete()` pada staff tanpa surat di test. Bila ada FK lain yang menghalangi, cukup ubah nama dan jabatan. |
| 4 | minor | AC-11 | Sebagian besar tumpang tindih dengan AC-2. Nilainya ada pada `received_by` yang berbeda dari penandatangan dan pada properti log. | Pertahankan sebagai satu test, tidak perlu tambahan. |

Dicek: semua kriteria issue #31 tercakup. Snapshot dua kolom string nullable portabel di SQLite/MySQL/Postgres. `Payment::create` hanya dari `RecordPayment`. Tidak ada permission baru, dan `view:finance-numbering` plus `abort_unless` pada `save()` sudah memenuhi kriteria hak akses. Tidak ada migrasi data demo atau abstraksi berlebih. 11 AC, ukuran sesuai.
