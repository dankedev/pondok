# F046 konten-footer-widget — Footer portal sebagai area widget

Status rencana: APPROVED (keputusan orkestrator setelah revisi 1, 2026-10-17; revisi 2 pemangkasan YAGNI tanpa review ulang)

## Ringkasan & tujuan
Paket 2 audit, keputusan user "Footer sebagai area widget" (`docs/audits/ui-ux-portal-konten.md` §9, juga §2.11, §5.5, C2). Footer F043 (deskripsi, satu teks pendek, saklar kontak/sosial, navigasi footer di halaman Navigasi) diganti **paling banyak 4 widget** yang disusun admin memakai mesin blok F045 (area `footer`). Baris hak cipta dan footer ringkas (PSB, 404) tetap di luar widget.

## Ruang lingkup
- Termasuk: area `footer` di mesin blok; 4 tipe widget; halaman admin Footer (Builder); bagian Navigasi footer dihapus dari halaman Navigasi; migrasi data F043; render footer penuh dan ringkas; data demo (isi `DemoData`); `docs/erd.md`, `docs/prd.md`.
- Tidak termasuk (eksplisit): ikon media sosial (tetap teks); footer per halaman; widget di luar 4 tipe; perubahan baris hak cipta; blok Ajakan/Kutipan di footer (keputusan user: hanya beranda); **memilih sebagian kontak/akun sosial** (widget Kontak dan Media sosial selalu menampilkan semua data Kontak & sosial, seperti footer F043); saklar alamat/email/peta per widget; `key` untuk akun sosial; kode perbaikan data demo lama.

## Model data
**Keputusan user (17 Okt 2026, via orkestrator):** Q1–Q3 diterima dengan usulan default.

Tanpa perubahan skema tabel. Settings:
- `portal.footer_widgets` (JSON, baru): list ≤4 widget `{type, visible: bool, data}` (batas dihitung termasuk yang tersembunyi). Tidak ada = tanpa widget. Semua widget punya `heading` opsional ≤60 (`h2` ukuran kecil, level diatur sistem).

| type | data | Tidak dirender bila |
|---|---|---|
| `text` | `body` teks terbatas F045, HTML tersanitasi ≤6000 karakter, `show_logo` bool (tile logo + nama lembaga) | body kosong dan `show_logo` false |
| `links` | `items` 1–12 Item navigasi F043 tanpa grup (label ≤60) | tidak ada item tersedia |
| `social` | (hanya `heading`) semua akun `school.social_links` | tidak ada akun |
| `contacts` | (hanya `heading`) semua isi Kontak & sosial yang dirender `contact-list` sekarang | tidak ada data kontak |

- Batas `body` satu angka pada HTML tersanitasi: teks terlihat migrasi maks. 500 + 60 + 300 = 860; escaping terburuk (`"`/`'` → `&quot;`/`&#039;`, ×6) + tag ≈ 5200 < 6000, jadi widget hasil migrasi selalu lolos validasi tanpa dipangkas.
- Sanitasi saat baca: `ParseContentBlocks` area `footer` memakai aturan F045 — `body` lewat `SanitizeBlockText`, `url` item `links` diperiksa aturan URL aman F045 (tidak aman → item dibuang).
- Key dihapus: `portal.footer_description`, `portal.footer_text`, `portal.footer_show_contacts`, `portal.footer_show_social`, `portal.navigation_footer`.

**Migrasi** `database/migrations/2026_10_20_000001_move_portal_footer_to_widgets.php` (pola F043/F045, deteksi instalasi lama sama dengan F045):
- Lewati bila `portal.footer_widgets` sudah ada. Instalasi baru → tanpa widget.
- Instalasi lama → kandidat berurutan seperti tata letak sekarang: `text` (`show_logo` true, body = deskripsi ter-escape ber-`<p>`); `contacts` bila `footer_show_contacts` ≠ false; `social` bila `footer_show_social` ≠ false; `links` dari `navigation_footer` (tidak ada → bawaan `psb.status`, `letters.verify`; `[]` → tidak dibuat) dengan heading "Tautan"; `text` dari `footer_text` (heading = title, body = isi ter-escape, baris baru → `<br>`).
- Bila kandidat > 4: teks pendek digabung ke akhir widget teks pertama (`<p><strong>judul</strong></p><p>isi</p>`), sehingga tidak ada data hilang.
- Hapus key lama. `down()` sederhana: hanya menghapus `portal.footer_widgets`; key lama **tidak dipulihkan** (dicatat di docblock migrasi).

## Action class
- `app/Actions/Konten/ParseContentBlocks`, `NormalizeContentBlocks` (diubah) — katalog area `footer`; galat `footer_widgets.{i}.data.{field}`.
- `app/Actions/Konten/UpdatePortalFooter` (ditulis ulang) — input `{footer_widgets}` + actor; > 4 ditolak `list_too_long`; log `portal_footer_updated` hanya bila berubah.
- `app/Actions/Konten/GetPortalFooter` (baru; dipakai layout penuh dan ringkas) — input profil; output `{widgets: list<RenderWidget>, compactLinks: list<Link>}`; tautan semua widget lewat satu panggilan `GetPortalNavigation::resolveItems()` (tautan ke halaman terhapus otomatis tidak tersedia); `compactLinks` = tautan dari widget `links` tampil pertama yang punya minimal satu tautan tersedia.
- `app/Actions/Konten/GetPortalNavigation`, `UpdatePortalNavigation` (diubah) — tanpa `footer` (konstanta `DEFAULT_FOOTER`, `MAX_FOOTER`, key `portal.navigation_footer` dihapus).
- `app/Actions/Konten/DeleteStaticPage` (diubah) — hanya mengeluarkan `portal.navigation_footer` dari `REFERENCE_KEYS`; tidak menyentuh `footer_widgets` (render menyembunyikan tautan yang tak tersedia, form membuang item berujukan hilang saat diisi; sama dengan beranda F045).
- `app/Actions/Konten/GetSchoolProfile` (diubah) — `KEYS` tanpa key footer lama, tambah `portal.footer_widgets`; output `footer` diganti `footer_widgets` mentah.

## UI (Filament / Blade)
- `app/Filament/Pages/Site/Footer.php`: `Builder` `footer_widgets` `maxItems(4)`, blok Teks (RichEditor `bold`/`link`, Toggle logo), Tautan (Repeater dengan `LinkTargetFields` F045), Media sosial dan Kontak (hanya judul + teks petunjuk bahwa isinya dari halaman Kontak & sosial). Toggle "Tampilkan" per widget. Item tautan berujukan hilang dibuang saat form diisi.
- `app/Filament/Pages/Site/Navigation.php`: section Navigasi footer dihapus; helper menunjuk widget Tautan di halaman Footer.
- Blade `resources/views/portal/partials/footer.blade.php` + `resources/views/portal/footer-widgets/{type}.blade.php`: grid kolom `lg` = jumlah widget tampil (1–4) lewat kelas literal (`lg:grid-cols-1`…`lg:grid-cols-4` ditulis utuh), `md` maks. 2, bertumpuk di seluler; widget `links` dibungkus `nav` dengan `aria-labelledby` ke heading, atau `aria-label` dari `lang/id` bila heading kosong; target sentuh 44px; fokus `ring-accent-400`. Kontak memakai `contact-list` yang ada tanpa filter. Ringkas: nama, `compactLinks` sebaris, hak cipta. Penuh tanpa widget: hanya baris hak cipta.
- Teks `lang/id/pondok.php` (`school_profile.widgets.*`). Design guide §0, §2 (footer navy satu-satunya section gelap), §6, §10.

## Permission & cakupan
Tidak ada permission baru. Halaman Footer dan Navigasi tetap `view:school-profile`. Tanpa `unit_user`. Item tautan mengikuti aturan ketersediaan F043 (`modules.konten|psb|dokumen.enabled`).

## Kriteria penerimaan (bisa dites)
- **AC-1** Migrasi instalasi lama (deskripsi, teks pendek, saklar on, navigasi footer tersimpan) → widget `text`, `contacts`, `social`, `links` dengan teks pendek digabung ke widget teks pertama; tanpa teks pendek → urutan apa adanya; navigasi footer tidak ada → `links` bawaan; saklar off → widget itu tidak dibuat; key lama terhapus. Nilai maksimum legacy (deskripsi 500, judul 60, isi 300 berisi `&`/`<`) digabung utuh dan widget hasilnya lolos `NormalizeContentBlocks` tanpa diubah.
- **AC-2** Migrasi: instance tersemai dianggap instalasi lama (aturan F045); instalasi baru tanpa widget; jalan kedua tanpa perubahan; `down()` hanya menghapus `portal.footer_widgets`.
- **AC-3** Footer setelah migrasi menampilkan nama, logo, deskripsi, kontak, sosial, tautan (label, URL, urutan sama dengan sebelum migrasi), dan teks pendek.
- **AC-4** Simpan valid: tiap tipe widget, urutan dan `visible` tersimpan ternormalisasi; satu log `portal_footer_updated`; tanpa perubahan tanpa log.
- **AC-5** Simpan tidak valid → `ValidationException` di field yang tepat, settings tidak berubah: widget ke-5, tipe tak dikenal (termasuk `quote`, `cta`, `hero`), item tautan grup/URL tak aman/> 12/0, heading > 60, `body` > 6000 setelah sanitasi; hasil migrasi maksimum (AC-1) diterima.
- **AC-6** Render: widget tampil sesuai urutan dan heading opsional; `visible=false` dan widget kosong (tautan tak tersedia termasuk halaman terhapus, teks kosong tanpa logo, data kontak/sosial kosong) tidak dirender; widget Kontak/Sosial menampilkan semua data Kontak & sosial terkini; kelas grid sesuai jumlah widget tampil; tidak ada widget → footer hanya hak cipta; widget `links` ber-`nav` berlabel. **Saat baca:** nilai berbahaya yang ditulis langsung ke `settings` (`<script>` dan href `javascript:` di `body`, `url` `//evil.test` di item `links`) tidak muncul sebagai markup/tautan aktif.
- **AC-7** Footer ringkas di `/psb`, `/psb/status`, dan 404: nama, tautan dari widget `links` tampil pertama yang punya tautan tersedia (widget pertama yang semua tautannya tak tersedia dilewati), hak cipta; tanpa widget teks, kontak, sosial; halaman lain memakai footer penuh.
- **AC-8** Navigasi: `UpdatePortalNavigation` tidak menerima/menulis footer; `GetPortalNavigation` tanpa `footer`; `DeleteStaticPage` tidak lagi merujuk `portal.navigation_footer`.
- **AC-9** Admin: halaman Footer render untuk super-admin, admin-sekolah, admin-konten (403 lainnya); Builder terisi dari settings (item tautan berujukan hilang dibuang), tombol tambah nonaktif setelah 4 widget, simpan memanggil Action, galat bersarang tampil di field benar; halaman Navigasi tanpa section footer.
- **AC-10** Tanpa konten bawaan & demo: instalasi baru tanpa DemoSeeder tidak punya widget; DemoSeeder (dua kali, idempotent) mengisi 4 widget fiktif dari `DemoData` (Teks dengan logo, Kontak, Media sosial, Tautan).

## Rencana test
| AC | File | Test yang diusulkan |
|---|---|---|
| 1–2 | `tests/Feature/Database/Migrations/MovePortalFooterToWidgetsTest.php` | `migrates footer into widgets`, `merges short text when over four`, `keeps legacy max-length values valid`, `skips fresh install`, `is idempotent`, `down drops widgets` |
| 4–5 | `tests/Feature/Actions/Konten/UpdatePortalFooterTest.php` (tulis ulang) | `saves every widget type`, `rejects invalid widgets` (dataset) |
| 3, 6–7 | `tests/Feature/Http/Portal/PortalFooterTest.php` (tulis ulang), `tests/Feature/Actions/Konten/GetPortalFooterTest.php` | `renders widgets in order`, `skips empty widgets`, `compact footer`, `same output after migration`, `sanitizes stored widgets on read` |
| 8 | `GetPortalNavigationTest.php`, `UpdatePortalNavigationTest.php`, `DeleteStaticPageTest.php` (ubah) | `navigation has no footer` |
| 9 | `tests/Feature/Filament/Pages/FooterPageTest.php` (tulis ulang), `NavigationPageTest.php` (ubah) | `access per role`, `limits to four widgets`, `saves and maps nested errors` |
| 10 | `NoBuiltInContentTest.php`, `DemoSeederTest.php`, `KontenSeedersTest.php` (tambah) | `fresh install footer has no widgets`, `demo footer idempotent` |

Test lama yang mengunci perilaku usang, ditulis ulang di file yang sama (fase RED): `UpdatePortalFooterTest`, `UpdatePortalNavigationTest`, `GetPortalNavigationTest`, `DeleteStaticPageTest`, `F037ActionVerifyGapsTest`, `GetSchoolProfileTest`, `FooterPageTest`, `NavigationPageTest`, `InstitutionProfileTest`, `SiteNavigationGroupsTest`, `StaticPageResourceTest`, `PortalFooterTest`, `InstitutionFooterContactTest`, `F037VerifyGapsTest`, `F043VerifyGapsTest`, `InstitutionVerifyGapsTest`, `NoBuiltInContentTest`, `DemoSeederTest`, `KontenSeedersTest`, helper `navSet()` di `tests/Pest.php` (tanpa `$footer`). `MovePortalNavigationSettingsTest` cukup diperiksa. Tidak ada file test yang dihapus; `UpdateSchoolContactsTest` dan `Settings/Contacts` tidak berubah.

## Urutan implementasi
**Paket A — data + Action** (hijau: `MovePortalFooterToWidgetsTest`, `UpdatePortalFooterTest`, `GetSchoolProfileTest`, `GetPortalNavigationTest`, `UpdatePortalNavigationTest`, `DeleteStaticPageTest`, `GetPortalFooterTest`)
1. Migrasi + test. 2. Katalog `footer` + `UpdatePortalFooter`. 3. `GetPortalFooter`. 4. Lepas footer dari `GetPortalNavigation`/`UpdatePortalNavigation`/`DeleteStaticPage`; `GetSchoolProfile`.

**Paket B — portal + admin + demo** (hijau: `PortalFooterTest`, `InstitutionFooterContactTest`, `FooterPageTest`, `NavigationPageTest`, `DemoSeederTest`, `KontenSeedersTest`, `NoBuiltInContentTest`, test lama yang ditulis ulang)
5. Footer Blade + widget + varian ringkas. 6. Halaman Footer (Builder), Navigation. 7. `lang/id`. 8. `DemoData`/`DemoContent`. 9. `docs/erd.md`, `docs/prd.md`. 10. Pint. Cek 360/1024/1440px.

## Risiko & pertanyaan terbuka
- **Q1 (diputuskan: widget Tautan pertama yang punya tautan tersedia):** footer ringkas memakai tautan dari widget Tautan pertama saja.
- **Q2 (diputuskan: ya):** batas 4 dihitung termasuk widget tersembunyi.
- **Q3 (diputuskan: diterima):** migrasi menulis judul "Tautan" sebagai heading widget hasil migrasi.
- Ditunda sebagai catatan (bukan dikerjakan): memilih sebagian kontak/akun sosial per widget dan saklar alamat/email/peta. Audit §5.5 hanya meminta kontak dan sosial otomatis dengan saklar tampil/sembunyi, yang sudah tercakup dengan menambah/menghapus widget. Bila user meminta, jadi entri baru.
- Catatan implementasi: bergantung pada sanitasi saat baca dan aturan URL aman F045; F046 baru boleh mulai setelah F045 `done`.
- Risiko: footer 4 kolom di 1024px bisa sempit; `lg` 4 kolom hanya bila 4 widget, `md` maks. 2.

## Riwayat revisi
- 17 Okt 2026: draf awal (product-manager, sesi orchestrator-bc5d2383).
- 17 Okt 2026, revisi 1 (Review #1): M1 batas `body` dihitung pada teks terlihat, maksimum gabungan migrasi 860 dinyatakan muat, AC-1/AC-5 + test nilai maksimum legacy; M2 sanitasi `body` dan URL item `links` saat baca lewat `ParseContentBlocks`, AC-6 + test render nilai berbahaya di settings. Keputusan user Q1–Q3 dicatat. Minor: `DeleteStaticPage` cukup buang item (AC-9), key sosial diwarisi saat simpan ulang tanpa key (AC-10 + test), `compactLinks` dari widget `links` pertama yang punya tautan tersedia (AC-8), `nav` berlabel dan peta kelas grid literal (AC-6).
- 17 Okt 2026, revisi 2 (pemangkasan YAGNI, arahan user "KISS, DRY, YAGNI", keputusan orkestrator tanpa review ulang): dibuang logika mengganti widget demo lama (demo dari `DemoData`); pemilihan kontak/akun per widget (`all`/`keys`, validasi `contact_not_found`/`social_not_found`) beserta `key` akun sosial, migrasi key, perubahan `UpdateSchoolContacts` dan `Settings/Contacts`, dan pewarisan key; saklar alamat/email/peta; hitungan "teks terlihat" + batas ganda (jadi satu batas HTML tersanitasi ≤6000, tetap memuat hasil migrasi maksimum); pembersihan widget di `DeleteStaticPage` (render dan form sudah menangani rujukan hilang); `down()` pemulih key lama (kini hanya hapus `footer_widgets`). Tiga paket jadi dua. AC 11 → 10 (belum ada test RED, nomor disusun ulang: AC-7 lama digabung ke AC-6, AC-8 → AC-7, AC-9 → AC-8, AC-10 → AC-9, AC-11 → AC-10). Revisi 1 M1 (batas teks terlihat) diganti batas HTML yang tetap menjamin migrasi tanpa kehilangan data; M2 dipertahankan.

## Review #1 — 2026-10-17

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | Model data `text` vs migrasi | Batas `body` ≤1000 tidak jelas dihitung pada HTML atau teks. Migrasi menghasilkan HTML ter-escape ber-`<p>` (`&amp;`, `<br>`), dan penggabungan "teks pendek ke widget teks pertama" menjumlah deskripsi (maks. 500 F043), judul (60), dan isi (300) plus tag. Hasilnya bisa melewati 1000, sehingga admin tertolak saat menyimpan widget yang tidak ia ubah, atau migrasi memangkas dan data hilang. | Hitung batas pada teks terlihat (setelah `strip_tags` dan decode entity), bukan HTML. Teks terlihat maksimum migrasi (500+60+300 = 860) lalu muat dalam 1000. Tambah kasus uji migrasi dengan nilai maksimum legacy. Terapkan juga di AC-5 secara eksplisit. |
| 2 | major | Sanitasi saat baca (bergantung F045 #2) | `footer_widgets` dibaca mentah dari settings dan `body` dirender sebagai HTML. Bila F045 hanya menyanitasi saat simpan, nilai hasil migrasi atau ubahan manual tidak tersanitasi, dan URL item `links` harus dibuang saat baca seperti pola F036/F043. | Pastikan `ParseContentBlocks` (area `footer`) menyanitasi `body` dan memvalidasi URL saat baca. Tambah satu uji render dengan nilai berbahaya yang ditulis langsung ke `settings`. |
| 3 | minor | `DeleteStaticPage` | Menghapus seluruh widget `links` secara otomatis bila item terakhir hilang, bukan sekadar tidak dirender. Aksi merusak yang tidak terlihat admin (heading dan susunan hilang) dan tidak konsisten dengan beranda (F045 hanya "tidak dirender"). Penghapusan kategori/album tidak ditangani sama. | Cukup buang item rujukan; biarkan widget kosong tidak dirender (AC-6 sudah menjamin). Atau catat perilaku sebagai keputusan eksplisit. |
| 4 | minor | Key sosial | Key dibawa lewat field tersembunyi repeater. Bila admin menyimpan Kontak & sosial lewat jalur tanpa key (mis. impor atau test), key baru dibuat dan widget terpilih diam-diam menyusut. | Uji `UpdateSchoolContacts`: simpan ulang tanpa `key` pada akun yang URL/platform sama mempertahankan key lama atau menolak; sebutkan yang dipilih. |
| 5 | minor | Footer ringkas/AC-8 | `compactLinks` dari widget `links` pertama yang tampil: belum jelas bila widget itu bukan item yang tersedia (semua tak tersedia) apakah beralih ke widget `links` berikutnya. | Tulis: pilih widget `links` pertama yang punya minimal satu tautan tersedia. |
| 6 | minor | Aksesibilitas/Tailwind | Widget `links` perlu `nav` berlabel (heading atau `aria-label` bila heading kosong). Kelas `lg:grid-cols-{n}` dinamis tidak masuk build tanpa Node saat runtime. | Gunakan `aria-labelledby`/`aria-label`; peta kelas literal untuk 1–4 kolom. |

Hal yang sudah baik: migrasi footer (maks. 5 kandidat, digabung tanpa kehilangan data), urutan tampil sama dengan F043, `all` vs `keys` dan penolakan `all=false` tanpa key, AC-9 memutus `footer` dari navigasi, 11 AC dalam kisaran, query tetap satu lewat `KEYS`, DB-agnostik. Dependensi F045 (`done` belum; status `in-progress`) harus selesai dulu, termasuk perbaikan sanitasi saat baca.
