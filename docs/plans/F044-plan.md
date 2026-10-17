# F044 konten-kontak-susunan-admin — Kontak lembaga satu sumber dan susunan menu admin Situs

Status rencana: APPROVED (review #1, 2026-10-17)

## Ringkasan & tujuan
Paket 1 audit (`docs/audits/ui-ux-portal-konten.md` §3.1, §3.3, §5.1, §5.6, S5, C7), lanjutan F043. Saat ini alamat, telepon kantor, dan email diisi di *Identitas*, daftar kontak di *Kontak & lokasi*, dan halaman `/kontak` demo mengetik ulang semuanya (nomor tampil tiga kali dengan format berbeda). F044 menjadikan *Kontak & sosial* satu-satunya sumber, telepon kantor menjadi kontak berlabel yang bisa ditandai "dicetak di kop dan kuitansi", dan merapikan menu admin: grup **Situs** dan grup **Pengaturan**.

## Ruang lingkup
- Termasuk: pindah field alamat/email ke Kontak & sosial; tanda cetak pada kontak; migrasi `school.phone`; sumber identitas cetak untuk kuitansi dan surat; daftar kontak portal tanpa baris telepon terpisah; data demo halaman Kontak; pembubaran cluster Profil lembaga menjadi halaman di grup Situs/Pengaturan; pembaruan `docs/erd.md`.
- Tidak termasuk (eksplisit): memindah logo cetak atau nama Arab ke pengaturan kop surat (tetap di Identitas); template halaman Kontak (S4); kontak PSB (F038); halaman Tampilan/tema; redirect URL admin lama; perubahan permission.

## Model data
Tanpa perubahan skema tabel. Settings:
- `school.contacts` item menjadi `{key, label, name, phone, is_whatsapp, is_print}`; `is_print` bool, paling banyak satu `true`. Item lama tanpa `is_print` dibaca `false`.
- `school.address`, `school.email`, `school.map_url` tetap (pemiliknya pindah ke `UpdateSchoolContacts`).
- `school.phone` dihapus.

**Migrasi** `database/migrations/2026_10_19_000001_move_school_phone_to_contacts.php` (pola `move_school_profile_settings`):
- `school.phone` kosong/tidak ada → tidak ada perubahan (idempotent: jalan kedua tidak menemukan key).
- Nomor ternormalisasi sama dengan kontak yang ada → kontak itu diberi `is_print = true` (tanda lain dimatikan), tanpa kontak baru.
- Tidak ada yang sama → tambah `{key baru 8 [a-z0-9], label: 'Kantor', name: null, phone, is_whatsapp: false, is_print: true}` di akhir daftar.
- `school.phone` dihapus; semuanya dalam satu transaksi.
- `down()`: tulis `school.phone` dari kontak `is_print` (bila ada); kontak tidak dihapus dan tanda dibiarkan (lossy wajar, didokumentasikan).

## Action class
- `app/Actions/Konten/UpdateSchoolContacts` (diubah) — input tambahan `address` (≤500), `email` (valid, ≤255), `contacts.*.is_print`; > 1 kontak bertanda → galat `contacts` `print_contact_multiple`, tidak ada yang ditulis; log tetap `school_contacts_updated`.
- `app/Actions/Konten/UpdateSchoolProfile` (diubah) — tidak lagi membaca atau menulis `school.address`, `school.phone`, `school.email` (input itu diabaikan, nilai tersimpan tidak disentuh).
- `app/Actions/Konten/GetSchoolProfile` (diubah) — `school.phone` keluar dari `KEYS` dan output; kontak membawa `is_print`; `hasContactData()` tanpa `phone`.
- `app/Actions/Konten/GetPrintIdentity` (baru) — output `{name, address, phone}`: nama dengan fallback `config('app.name')`, alamat, telepon kontak `is_print` (null bila tidak ada). Satu query settings.
- `app/Actions/Dokumen/IssueLetter` dan `app/Http/Controllers/Keuangan/ShowPaymentReceipt` (diubah) — blok `school` diambil dari `GetPrintIdentity`; logo tetap `school.logo_path`. Snapshot surat yang sudah terbit tidak disentuh.

## UI (Filament / Blade)
- Cluster `InstitutionProfileCluster` dihapus. Halaman dipindah ke `app/Filament/Pages/Site/{Home,Navigation,Footer}.php` (grup `pondok.navigation.situs`) dan `app/Filament/Pages/Settings/{Identity,Contacts}.php` (grup `pondok.navigation.pengaturan`); kelas dasar `InstitutionProfilePage` dipindah ke `app/Filament/Pages/Concerns/` atau `app/Filament/Pages/SettingsFormPage.php` tanpa perubahan perilaku. Slug: `site/home`, `site/navigation`, `site/footer`, `settings/identity`, `settings/contacts`.
- Urutan grup Situs: Beranda 1, Navigasi 2, Footer 3, Halaman 4, Berita 5, Kategori 6, Galeri 7, Video 8 (`PostResource`, `StaticPageResource`, `CategoryResource`, `AlbumResource`, `VideoResource` pindah dari `navigation.konten`). Grup Situs mengambil posisi grup Konten. Kunci `navigation.konten` dihapus bila tak terpakai.
- *Identitas*: section alamat & kontak resmi dihapus (nama, nama singkat, nama Arab, slogan, logo tetap). Helper logo cetak tetap.
- *Kontak & sosial* (ganti label "Kontak & lokasi"): section Alamat & lokasi (alamat, email, tautan peta), Kontak (repeater + toggle `is_print` "Cetak di kop surat dan kuitansi" dengan helper), Media sosial.
- Blade `portal/partials/contact-list.blade.php`: baris telepon kantor dihapus; kontak ditampilkan dari daftar (ikon telepon bila bukan WhatsApp).
- Teks baru di `lang/id/pondok.php`. Design guide §6 (Section berjudul, urutan identitas → kontak), §11 (kop dari settings).

## Permission & cakupan
Tidak ada permission baru dan tidak ada perubahan pemberian peran. Lima halaman tetap memakai `view:school-profile` (super-admin, admin-sekolah, admin-konten); resource konten tetap dengan policy dan saklar `modules.konten.enabled` yang ada. Tanpa `unit_user`.

## Kriteria penerimaan (bisa dites)
- **AC-1** Migrasi telepon: (a) `school.phone` sama (setelah normalisasi) dengan kontak yang ada → kontak itu `is_print`, jumlah kontak tetap; (b) berbeda → kontak "Kantor" non-WhatsApp bertanda cetak ditambahkan dengan key valid unik; (c) tanpa `school.phone` → `school.contacts` tidak berubah; `school.phone` terhapus; jalan kedua tanpa perubahan; `down()` menulis kembali `school.phone` dari kontak bertanda.
- **AC-2** Cetakan sama: dengan data lama (nama, alamat, telepon kantor, logo cetak) yang dimigrasikan, HTML kuitansi pembayaran dan surat yang diterbitkan setelah migrasi memuat nama, alamat, dan teks telepon yang identik dengan sebelum migrasi; tanpa kontak bertanda, baris telepon tidak dirender.
- **AC-3** Snapshot: mengganti kontak bertanda tidak mengubah cetakan surat yang sudah diterbitkan, tetapi surat baru dan kuitansi memakai telepon baru.
- **AC-4** `UpdateSchoolContacts`: menyimpan alamat, email, dan `is_print`; email tidak valid, alamat > 500, atau dua kontak bertanda → `ValidationException` pada field yang tepat dan settings tidak berubah; key kontak lama tetap.
- **AC-5** `UpdateSchoolProfile`: menyimpan identitas dengan payload berisi atau tanpa `address`/`phone`/`email` tidak mengubah `school.address`, `school.email`, maupun `school.contacts`.
- **AC-6** Portal: footer dan halaman `/kontak` menampilkan alamat, email, tautan peta, dan semua kontak (termasuk kontak bertanda cetak) masing-masing satu kali; tidak ada baris "Telepon" terpisah; halaman `kontak` tetap tersedia bila hanya alamat atau hanya kontak yang terisi.
- **AC-7** Admin Kontak & sosial dan Identitas: Identitas tidak punya field alamat/telepon/email; Kontak & sosial terisi dari settings dan menyimpan lewat Action; galat "dua kontak bertanda" tampil di form.
- **AC-8** Data demo: halaman `/kontak` demo tidak mengetik ulang alamat, telepon, atau email (isi hanya keterangan seperti jam layanan); setiap nomor demo muncul tepat sekali di halaman itu; DemoSeeder dua kali tetap idempotent dan memperbaiki isi halaman Kontak demo lama (yang memuat email demo) tanpa menyentuh halaman Kontak buatan admin lain; kontak demo fiktif.
- **AC-9** Susunan admin: grup Situs berisi tepat Beranda, Navigasi, Footer, Halaman, Berita, Kategori, Galeri, Video dalam urutan itu; grup Pengaturan memuat Identitas dan Kontak & sosial; tidak ada item atau cluster "Profil lembaga".
- **AC-10** Akses per peran tidak berubah: super-admin, admin-sekolah, admin-konten membuka kelima halaman (200) dan melihatnya di navigasi; bendahara, admin-psb, kesantrian, musyrif, ustadz-tahfidz, petugas-pos, dan wali mendapat 403 dan tidak melihatnya; matriks akses resource konten sama dengan sebelum F044.

## Rencana test
| AC | File | Test yang diusulkan |
|---|---|---|
| 1 | `tests/Feature/Database/Migrations/MoveSchoolPhoneToContactsTest.php` | `marks matching contact for print`, `appends office contact`, `does nothing without phone`, `is idempotent`, `rolls back phone` |
| 2–3 | `tests/Feature/Http/Keuangan/PaymentReceiptTest.php`, `tests/Feature/Http/Dokumen/IssuedLetterPrintTest.php`, `tests/Feature/Actions/Dokumen/IssueLetterTest.php` (tambah) | `prints same identity after migration`, `omits phone without print contact`, `keeps snapshot of issued letter` |
| 4–5 | `tests/Feature/Actions/Konten/UpdateSchoolContactsTest.php`, `UpdateSchoolProfileTest.php` (tambah/ubah) | `saves address email and print flag`, `rejects invalid contact input` (dataset), `identity does not touch contact fields` |
| 6 | `tests/Feature/Http/Portal/InstitutionFooterContactTest.php` (ubah) | `shows each contact once`, `contact page available with address only` |
| 7, 9–10 | `tests/Feature/Filament/Pages/SiteNavigationGroupsTest.php` (baru), `InstitutionProfileTest.php`/`SchoolProfileTest.php` (ubah ke kelas baru) | `groups admin navigation`, `access per role` (dataset peran), `contacts form saves and maps errors` |
| 8 | `tests/Feature/Database/Seeders/DemoSeederTest.php`, `KontenSeedersTest.php` (tambah) | `demo contact page has no duplicated numbers`, `repairs old demo contact page` |

Test lama yang memakai kelas cluster (`InstitutionMenuTest`, `InstitutionProfileTest`, `SchoolProfileTest`, `SchoolProfileReceiptTest`, `KontenVerifyGapsTest` Filament) atau `school.phone` (`GetSchoolProfileTest`, `MoveSchoolProfileSettingsTest` bila menyentuh nomor kantor) ditulis ulang oleh red-tester di fase RED.

## Urutan implementasi
**Paket A — data, Action, cetak** (hijau: `MoveSchoolPhoneToContactsTest`, `UpdateSchoolContactsTest`, `UpdateSchoolProfileTest`, `GetSchoolProfileTest`, `PaymentReceiptTest`, `IssuedLetterPrintTest`, `IssueLetterTest`)
1. Migrasi + test. 2. `GetSchoolProfile` (`is_print`, tanpa `phone`). 3. `UpdateSchoolContacts`. 4. `UpdateSchoolProfile`. 5. `GetPrintIdentity` + pemakaian di `IssueLetter` dan `ShowPaymentReceipt`.

**Paket B — admin dan portal** (hijau: `SiteNavigationGroupsTest`, test halaman Filament yang ditulis ulang, `InstitutionFooterContactTest`)
6. Pindah halaman dari cluster ke `Pages/Site` dan `Pages/Settings`, hapus cluster. 7. Grup `situs` untuk lima resource + urutan. 8. Form Identitas dan Kontak & sosial. 9. `contact-list.blade.php`. 10. `lang/id`.

**Paket C — demo dan dokumen** (hijau: `DemoSeederTest`, `KontenSeedersTest`)
11. `DemoData`/`DemoContent` (kontak "Kantor" bertanda cetak, halaman Kontak tanpa duplikasi, perbaikan isi lama). 12. `docs/erd.md` (key settings), `docs/demo.md` bila menyebut menu admin. 13. Pint, cek admin di desktop dan portal `/kontak` di 360px.

## Risiko & pertanyaan terbuka
- **Q1 (user):** label kontak hasil migrasi "Kantor" ditulis migrasi (data, bukan teks UI). Usulan: diterima; admin bisa menggantinya.
- **Q2 (user):** halaman Footer ikut di grup Situs (setelah Navigasi), walau tidak disebut di daftar user. Alternatif: digabung ke halaman Navigasi. Usulan: halaman sendiri.
- Risiko: daftar kontak sudah 10 lalu migrasi menambah satu → 11; penyimpanan berikutnya ditolak `list_too_long` sampai admin menghapus satu. Mitigasi: migrasi tetap menulis (tanpa kehilangan data), pesan galat sudah menjelaskan batas.
- Risiko: URL admin lama `/admin/institution-profile/*` menjadi 404 (bookmark admin). Tidak diberi redirect.
- Risiko: Filament Shield bisa membuat permission halaman baru saat `shield:generate`; halaman tetap memakai `canAccess()` dengan `view:school-profile`, jadi permission peran tidak berubah (dikunci AC-10).
- Minor: kontak bertanda cetak yang dihapus admin membuat cetakan tanpa telepon; helper toggle menjelaskannya.

## Riwayat revisi
- 17 Okt 2026: draf awal (product-manager, sesi orchestrator-bc5d2383).

## Review #1 — 2026-10-17

Verdict: APPROVED

Pemeriksaan: tidak ada skema baru; migrasi `school.phone` idempotent dan `down()` aman diulang (naik, turun, naik tidak menggandakan kontak). `GetSchoolProfile::contacts()` tidak memotong daftar, jadi kontak ke-11 tetap terbaca dan tercetak. Sumber telepon cetak lama memang `Setting::get('school.phone')` di `ShowPaymentReceipt` dan `IssueLetter` (blok `school` ikut snapshot surat), sehingga AC-2 dan AC-3 menutup perubahan yang berisiko. Tidak ada pembaca lain `$school['phone']` selain `contact-list.blade.php`. Permission tidak disentuh: `canAccess` lama (`view:school-profile`) ada di kelas dasar halaman dan dipertahankan; AC-10 mengunci matriks 10 peran.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Migrasi (cabang "tidak ada yang sama") | Nilai `phone` kontak baru tidak ditegaskan: disalin apa adanya atau dinormalisasi. AC-2 menuntut teks telepon cetak identik. Nilai lama dari `UpdateSchoolProfile::phone()` sudah ternormalisasi (spasi, `-`, `.`, kurung dibuang), tetapi data seed atau lama bisa mentah dan gagal validasi `^\+?[0-9]{8,15}$` pada penyimpanan kontak berikutnya. | Tulis: migrasi menyalin string tersimpan (hanya `trim`), pencocokan memakai normalisasi yang sama dengan `SavesProfileSettings::phone`. Tambah satu kasus test nilai tak valid tetap dipindahkan tanpa exception. |
| 2 | minor | Risiko daftar kontak 11 | Alamat dan email kini hanya diedit di halaman Kontak. Bila daftar berisi 11 kontak, seluruh penyimpanan halaman itu ditolak `list_too_long`, termasuk alamat dan email (sebelumnya terpisah di Identitas). Kasus langka (10 kontak sebelumnya). | Terima; pastikan galat `list_too_long` tampil pada repeater kontak dan test `contacts form saves and maps errors` mencakup kasus ini bila murah. |
| 3 | minor | Action `GetPrintIdentity` | Perilaku bila data tersimpan punya lebih dari satu `is_print` (diedit manual) tidak ditentukan. | Pilih yang pertama secara deterministik; cukup satu asersi. |
| 4 | minor | Rencana test / dependensi F043 | Test baru F043 (`NavigationPageTest`, `FooterPageTest`) merujuk kelas cluster dan akan pecah saat F044 memindahkan halaman, tetapi tidak tercantum di daftar test yang ditulis ulang. F043 juga masih `in-progress` sedangkan `depends_on` F044 mewajibkan `done` sebelum mulai. | Tambahkan kedua file itu ke daftar penulisan ulang RED F044; mulai F044 hanya setelah F043 `done`. |
| 5 | minor | UI (urutan grup) | Panel tidak mendefinisikan `navigationGroups()`; posisi grup Situs ditentukan oleh `navigationSort` item, jadi klaim "mengambil posisi grup Konten" tidak dijamin dan tidak diuji (AC-9 hanya isi dan urutan item). | Cek hasil di browser saat implementasi; bila posisi penting, set urutan lewat `navigationGroups()` di provider panel. |
| 6 | minor | Demo (AC-8) | Kriteria "halaman Kontak demo lama" harus ketat agar halaman `kontak` buatan admin tidak ditimpa. | Perbaiki hanya bila slug `kontak` dan isi memuat nilai email/telepon demo persis; uji dengan halaman kontak kustom sebagai pembanding. |

Tidak ada blocker maupun major. Ukuran rencana (87 baris, 10 AC) sesuai.
