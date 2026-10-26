# F028 tabungan-uang-saku — Dompet santri sederhana

Issue: #9
Status rencana: APPROVED

## Ringkasan & tujuan
Satu saldo uang saku/tabungan per santri, dicatat lewat ledger append-only: setor, tarik, dan koreksi. Bendahara mencatat dan melihat rekap di admin Filament; wali melihat saldo dan mutasi anaknya di portal wali. Keputusan user (26 Okt 2026): dompet sederhana, tanpa batas harian, tanpa tutup buku, tanpa dua jenis dompet.

Jalur: penuh (tabel baru, uang, permission, cakupan jenjang).

## Ruang lingkup
- Termasuk: tabel `wallet_transactions`; Action `RecordWalletTransaction` dan `VoidWalletTransaction`; resource Filament mutasi; halaman rekap saldo; bagian "Uang saku" di halaman santri portal wali; permission dan seeder peran; update `docs/erd.md`.
- Tidak termasuk (YAGNI): batas tarik harian, tutup buku/saldo awal periode, dompet kedua (tabungan vs uang saku), transfer antar santri, bunga, nomor kuitansi, bukti unggahan, notifikasi WhatsApp, widget dashboard, ekspor CSV, input massal, edit transaksi, pembayaran tagihan dari dompet, input oleh wali.

## Keputusan desain
- **Modul:** masuk modul **Keuangan** yang ada, tanpa case `Module` baru. Alasan: pencatatan uang oleh bendahara, peran dan saklar sama, dan portal wali sudah menyaring bagian Keuangan. Modul terpisah hanya menambah saklar yang tidak akan dimatikan sendiri (YAGNI). Resource `BelongsToModule` (`keuangan`), page `getModule()`, portal disaring `Module::Keuangan->isEnabled()`.
- **Saldo dihitung dari ledger, tanpa kolom cache:** `SUM(masuk) - SUM(keluar)` pada baris `berhasil`. Tidak ada saldo yang bisa menyimpang dari ledger (KISS). Konsistensi: tiap Action berjalan dalam `DB::transaction`, mengunci baris `students` (`lockForUpdate`, sama pola `RecordPayment` yang mengunci tagihan), menghitung saldo dari ledger, lalu menolak bila hasilnya negatif. Di SQLite penulisan sudah serial; di MySQL/Postgres kunci baris santri menyerialkan transaksi per santri.
- **Pembatalan:** baris tetap, `status = batal` beserta `cancelled_at/by/reason`; tidak ada baris lawan. Membatalkan `setor` atau `koreksi_tambah` ditolak bila saldo akan negatif.

## Model data
`wallet_transactions` (ledger, tanpa soft delete):
- `id`; `student_id` FK `students` `restrictOnDelete`; `type` enum string `setor | tarik | koreksi_tambah | koreksi_kurang`; `amount` `unsignedBigInteger` rupiah (1..999.999.999); `occurred_on` date; `note` string 255 nullable (wajib untuk koreksi); `status` `berhasil | batal` default `berhasil`; `recorded_by` FK `users` `restrictOnDelete`; `cancelled_at` nullable; `cancelled_by` FK `users` nullable `restrictOnDelete`; `cancel_reason` string 255 nullable; timestamps.
- Arah: `setor`, `koreksi_tambah` = masuk; `tarik`, `koreksi_kurang` = keluar. Enum `App\Enums\WalletTransactionType` dengan `isCredit()` dan `label()` (lang/id).
- Index: (`student_id`, `status`), (`status`, `occurred_on`). Tanpa unique. Kompatibel SQLite/MySQL/Postgres (tanpa fitur khusus DB).
- Model `WalletTransaction`: `HasUnitScope` (`applyUnitScope` lewat `student_id`, ikut pola `Payment`), `LogsActivity` log `keuangan` (`status`), relasi `student`, `recorder`. Pembantu saldo `WalletBalance::forStudent(int)` (query agregat satu tempat, dipakai Action, portal, dan rekap).

## Action class (`app/Actions/Keuangan/`)
- `RecordWalletTransaction::handle(Student $student, User $user, array $data): WalletTransaction` — `type`, `amount`, `occurred_on` (tidak di masa depan), `note`. Validasi: nominal bilangan bulat positif; santri terlihat oleh cakupan jenjang `$user`; koreksi wajib `note`; keluar tidak boleh melebihi saldo (pesan menyebut saldo). Satu baris activity `keuangan` per transaksi (log otomatis model dimatikan).
- `VoidWalletTransaction::handle(WalletTransaction $tx, User $user, string $reason): WalletTransaction` — alasan 1..255; sudah batal ditolak; mengunci santri lalu baris; membatalkan masuk yang membuat saldo negatif ditolak. Satu baris activity per pembatalan.
- `WalletBalance` (query helper, bukan Action) sudah di atas.
- Kesalahan lewat `ValidationException` dengan teks `lang/id` (`pondok.wallet.errors.*`).

## UI (Filament / Blade)
Baca `docs/design-guide.md` dan jalankan `/anti-ui-slop` sebelum UI.
- `WalletTransactionResource` (grup navigasi Keuangan): tabel mutasi (tanggal, santri, jenis, nominal rupiah, status, pencatat); filter jenis, status, santri, rentang tanggal; aksi header "Catat transaksi" (modal form memanggil Action, `ShowsActionErrorsOnForm`); aksi baris "Batalkan" (alasan wajib). Tanpa Edit/Delete.
- Page `WalletBalances` ("Rekap saldo uang saku"): tabel santri dengan saldo (subquery agregat), urut saldo, cari nama/NIS, total saldo semua santri terlihat di footer; format rupiah mengikuti design-guide.
- Portal wali: bagian "Uang saku" di `resources/views/portal-wali/student.blade.php` (saldo + 20 mutasi terakhir, batal diberi label), dimuat di `ShowStudent` hanya bila Keuangan aktif. Tanpa route baru; santri bukan milik wali tetap 404.
- Teks di `lang/id/pondok.php` (`pondok.wallet.*`).

## Permission & cakupan
- Shield resource `WalletTransaction`: `viewAny`, `view`, `create`, `void`; page `view:wallet-balances`. Seeder: `bendahara` mendapat semuanya; `super-admin` otomatis. Peran lain tidak. `wali` tanpa permission admin; akses lewat kepemilikan santri (pola portal).
- Cakupan `unit_user`: global scope pada `WalletTransaction`; Action menolak santri di luar cakupan; rekap memakai scope `Student`.
- Saklar `modules.keuangan.enabled`: resource/page hilang, portal menyembunyikan bagian. Data tidak dihapus.

## Kriteria penerimaan (bisa dites)
- AC-1 Setor: santri saldo 0, bendahara mencatat `setor` 50.000 → baris `berhasil` tersimpan, saldo 50.000, `recorded_by` terisi, tepat satu activity `keuangan`.
- AC-2 Tarik: saldo 50.000, `tarik` 20.000 → saldo 30.000; `tarik` 30.001 ditolak (ValidationException), tidak ada baris baru, saldo tetap 30.000; `tarik` tepat 30.000 berhasil (saldo 0).
- AC-3 Validasi masukan: nominal 0, negatif, desimal, non-angka, atau di atas 999.999.999 ditolak; tanggal masa depan ditolak; `note` >255 ditolak.
- AC-4 Koreksi: `koreksi_tambah` menambah dan `koreksi_kurang` mengurangi saldo; keduanya tanpa `note` ditolak; `koreksi_kurang` melebihi saldo ditolak.
- AC-5 Batal tarik: `VoidWalletTransaction` pada `tarik` → `status batal`, `cancelled_*` terisi, saldo kembali; baris tidak terhapus; activity tercatat.
- AC-6 Batal masuk: membatalkan `setor` ketika saldo sisa lebih kecil dari nominalnya ditolak (saldo tidak negatif); bila sisa cukup berhasil dan saldo berkurang.
- AC-7 Batal ganda atau alasan kosong/>255 ditolak; baris batal tidak dihitung dalam saldo dan tidak bisa diubah lagi.
- AC-8 Append-only: tidak ada route/aksi edit atau hapus; FK `student_id`/`recorded_by`/`cancelled_by` `restrictOnDelete` (menghapus permanen user atau santri ber-transaksi gagal); santri soft-deleted: baris tetap ada.
- AC-9 Konsistensi saldo: setelah deretan campuran setor/tarik/koreksi/batal, saldo `WalletBalance` sama dengan hitungan manual dan tidak pernah negatif; dua transaksi tarik berurutan yang total melebihi saldo, yang kedua ditolak (Action membaca saldo di dalam transaksi pada baris terkunci).
- AC-10 Akses admin per peran: `bendahara` dan `super-admin` melihat resource, rekap, dan bisa mencatat/membatalkan; `admin-sekolah`, `kesantrian`, `admin-konten`, `wali` tidak (403/tidak tampak di navigasi).
- AC-11 Cakupan jenjang: pengguna dengan `unit_user` jenjang A tidak melihat mutasi/saldo santri jenjang B di resource dan rekap, dan Action menolak mencatat/membatalkan untuk santri jenjang B; tanpa baris `unit_user` melihat semua.
- AC-12 Rekap saldo: halaman menampilkan saldo tiap santri (hanya `berhasil`), total footer sama dengan jumlah saldo, pencarian nama berfungsi.
- AC-13 Portal wali: wali melihat saldo dan mutasi anaknya (batal berlabel) di halaman santri; tidak melihat milik santri lain (404); bagian tidak tampil saat modul Keuangan mati; halaman santri tetap 200.
- AC-14 Kepatuhan modul: `ModuleComplianceTest` hijau (resource dan page terdaftar ke `keuangan`); saat Keuangan mati resource/page tidak dapat diakses.

## Rencana test
- `tests/Feature/Keuangan/RecordWalletTransactionTest.php`: AC-1..4, 9 (nama: `mencatat setor menambah saldo`, `menolak tarik melebihi saldo`, ...).
- `tests/Feature/Keuangan/VoidWalletTransactionTest.php`: AC-5..8.
- `tests/Feature/Keuangan/WalletTransactionResourceTest.php`: AC-10..12, 14 (Livewire test tabel, aksi, rekap).
- `tests/Feature/PortalWali/StudentWalletTest.php`: AC-13.
- `tests/Feature/ModuleComplianceTest.php`: tidak diubah, harus tetap hijau.

## Urutan implementasi
Paket 1 — data + Action (hijau: dua file test Action): migration, enum, model + factory, `WalletBalance`, kedua Action, `lang/id` pesan, seeder permission.
Paket 2 — Filament (hijau: `WalletTransactionResourceTest`): resource, page rekap, navigasi, `BelongsToModule`.
Paket 3 — portal + dokumen (hijau: `StudentWalletTest`, `ModuleComplianceTest`): bagian Blade dan `ShowStudent`; terapkan perubahan ERD; Pint.

## Perubahan `docs/erd.md` (diterapkan di paket 3)
- Bagian Keuangan, diagram: tambah entitas `wallet_transactions` (kolom di atas) dan relasi `students ||--o{ wallet_transactions`, `users ||--o{ wallet_transactions`.
- Tabel indeks: `wallet_transactions` | index (`student_id`, `status`), (`status`, `occurred_on`); semua FK `restrictOnDelete` | Rekap saldo dan mutasi per santri.
- Aturan bisnis: saldo uang saku = jumlah masuk dikurangi keluar pada transaksi `berhasil`, dihitung dari ledger (tanpa kolom saldo); tidak boleh negatif; transaksi tidak diubah atau dihapus, kesalahan dibatalkan (`batal`) dan koreksi lewat `koreksi_tambah/koreksi_kurang` beralasan; Action mengunci baris santri dalam transaksi DB.
- Daftar audit `activity_log`: tambahkan `wallet_transactions`.

## Risiko & pertanyaan terbuka
- Santri nonaktif/alumni: rencana tidak membatasi status (hanya bukan soft-deleted) agar sisa saldo bisa ditarik. Setuju, atau setor dibatasi santri aktif?
- Peran `kesantrian`/`musyrif`/petugas pos yang realistisnya memegang uang saku tidak diberi akses (YAGNI); tambah bila diminta.
- Penyimpangan dari issue: body issue hanya "Tabungan dan uang saku santri"; perlu diperbarui dengan cakupan dompet sederhana di atas.
- Perebutan kunci di SQLite tidak teruji paralel; AC-9 menguji urutan, bukan konkurensi nyata.

## Riwayat revisi
- (belum ada)

## Review #1 — 2026-10-26

Verdict: APPROVED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Permission & cakupan; AC-11 | `UnitScope` membaca `Auth::user()`, bukan parameter `$user` Action (`app/Models/Scopes/UnitScope.php`). `CancelPayment`/`RecordPayment` juga tidak memeriksa cakupan di Action. Bila Action hanya memakai `Student::find`, cek cakupan lolos diam-diam saat tanpa login (console/queue) atau saat `$user` berbeda dari yang login. | Cek eksplisit lewat `$user->scopedUnitIds()` terhadap `unit_id` santri (ambil santri dengan `withoutUnitScope()` lalu lock), bukan lewat global scope. Uji AC-11 juga tanpa `actingAs`. |
| 2 | minor | Keputusan santri nonaktif; AC-8 | Keputusan orkestrator (santri tidak soft-deleted boleh bertransaksi, termasuk nonaktif/alumni) belum jadi AC dan masih tertulis sebagai pertanyaan terbuka. Perilaku `VoidWalletTransaction` untuk santri soft-deleted juga belum ditentukan (risiko `findOrFail` 404, bukan `ValidationException`). | Tambah satu AC: santri alumni/nonaktif bisa setor/tarik; santri soft-deleted ditolak dengan `ValidationException` (setor, tarik, batal). Pindahkan pertanyaan itu ke keputusan. |
| 3 | minor | Model data; `applyUnitScope` | "ikut pola Payment" perlu menyebut santri diarsipkan tetap terhitung (query santri tanpa global scope, termasuk trashed), kalau tidak mutasi santri soft-deleted hilang dari resource bagi user ber-`unit_user`. | Tulis eksplisit di rencana dan uji satu kasus di AC-11. |
| 4 | minor | Permission | Nama aksi `void` berbeda dari pola `cancel` pada `Payment`/`Invoice` (`PaymentPolicy`, `RolePermissionSeeder`). Rencana juga tidak menyebut `WalletTransactionPolicy`. | Pakai `cancel`, daftarkan policy, dan tambah ke `bendahara` lewat `resourcePermissions('WalletTransaction', ['viewAny','view','create','cancel'])` plus `view:wallet-balances`. Periksa test seeder/peran yang menghitung permission. |
| 5 | minor | YAGNI | `koreksi_tambah`/`koreksi_kurang` menambah dua nilai enum, aturan `note` wajib, dan AC-4, padahal batal + input ulang sudah menutup salah catat. Disebut di ringkasan keputusan user, jadi boleh tetap. | Pertahankan hanya bila user memang memintanya; bila tidak, buang. |
| 6 | minor | AC-12 / rekap | Tidak jelas apakah rekap memuat semua santri (ribuan baris saldo 0) atau hanya yang punya transaksi, dan apakah total footer mengikuti pencarian. | Putuskan dan tulis di AC-12 (usul: santri ber-transaksi, total mengikuti kueri tersaring). |
| 7 | minor | `occurred_on` | "Tidak di masa depan" perlu zona waktu (`Asia/Jakarta`, seperti `RecordPayment`) agar tidak flaky di batas tengah malam. | Batas = tanggal hari ini `Asia/Jakarta`; uji hari ini lolos, besok ditolak. |

Catatan: tidak ada blocker atau major. Dependensi F007 `done`. Ukuran (14 AC, ±87 baris), modul Keuangan tanpa case baru, saldo dari ledger, `restrictOnDelete`, dan batasan cPanel sudah sesuai.
