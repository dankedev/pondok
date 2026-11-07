# F110 konten-editor-tema — Editor tema v0 (rencana)

Issue: #152
Status rencana: REVISED (menunggu keputusan orkestrator)
Jalur penuh: menulis berkas tema terpasang, menyentuh `InstallPortalTheme`, route portal yang di-cache publik, dan hak akses. PR implementasi ke `dev` dengan `Refs #152`; tidak ke `main`, katalog, atau pondok-web.
Sumber: `pondok-themes-work/PANDUAN-MEMBUAT-TEMA.md`, `CATATAN-DISTRIBUSI-TEMA.md`, `CELAH-SISTEM.md`, `LAPORAN-F109.md` (repo privat).

## Ringkasan & tujuan
Saat ini tema hanya bisa dibuat dengan menulis JSON dan CSS tangan lalu menjalankan `install-theme.sh`. Editor v0 memberi super-admin jalur cepat: **duplikat tema valid, ubah token warna dan susunan beranda, pratinjau, lalu aktifkan lewat Situs > Tampilan**. Tidak ada format paket baru. Hasilnya tema terpasang biasa di `storage/app/themes/lokal-<nama>/` yang diperiksa validator yang sama dengan tema resmi.

## Jawaban enam pertanyaan issue

**1. Yang diedit: duplikat lalu ubah token dan susunan, bukan editor CSS.**
- Sumber duplikat adalah tema valid apa pun dari `ListPortalThemes`. Byte disalin dari tempat ini: tema terpasang ber-`assets` menyalin `theme.json` dan isi `storage/app/themes/<slug>/assets/<hash12>/` milik `theme.json` itu (`theme.css`, `fonts/`). Tema resmi di `resources/themes/*` (default, centered, majalah, mushaf) dan tema tanpa `assets` (Amanah, Lapang) hanya menyalin `theme.json`, tanpa berkas aset.
- Yang bisa diubah ada empat. (a) `name` dan `description`. (b) Enum `palette`, `surface`, dan `font` (`ValidatePortalTheme::assertLook`); untuk tema tanpa CSS hanya ini yang mengubah tampilan. (c) Nilai custom property `--*` berliteral `#rrggbb` di blok scope tingkat atas `body[data-pt-theme="<slug>"]`. Daftarnya dibaca dari keluaran `ValidateThemeCss::handle()` (`rules` dengan `top` dan `selectors === [scope]`), karena tiap tema memberi nama tokennya sendiri: Cahaya punya 11 token hex, Mihrab 9, Kobalt 7. (d) Node puncak `home.layout`: urutan, tambah/hapus, `variant` (`ThemeComponents::VARIANTS`), dan `style` **skalar saja** dari `ThemeStyleMap::MAP` (`tone`, `align`, `width`, `radius`, `shadow`, `border`, `gap`, `padding.base`). Sudut, bayangan, dan jarak per blok diatur lewat (d).
- Alasannya: warna tema ber-CSS sudah terpusat di blok scope (aturan 6 PANDUAN), dan validator kontras membaca blok yang sama. Editor CSS bebas hanya mengulang pekerjaan di repo tema, padahal permukaan risikonya terbesar.
- Yang ditunda: editor CSS bebas, token non-warna di CSS (belum ada tema yang menjadikannya custom property), font baru atau ganti font, header/footer, `children` dari `container`/`columns` (dipertahankan apa adanya), dan menambah `blocks` legacy.

**2. Penyimpanan: tema terpasang berawalan `lokal-` yang ditulis lewat jalur yang sama dengan `InstallPortalTheme`.**
- `stageAssets`, `swap`, dan `placeAssets` dipindah dari `InstallPortalTheme` ke trait `Concerns\WritesInstalledThemes`. Trait ini sudah dipakai pasang, kembalikan, dan hapus, dan menangani staging, `ValidateThemePackage`, `assets/<hash12>`, `previous.json`, serta pangkas aset. Pemeriksaan tanda tangan, sha256, dan aturan versi naik tetap di `InstallPortalTheme`.
- Penanda tema lokal adalah slug `lokal-*` (lolos `SLUG_PATTERN`; sisa nama maksimal 34 karakter). Editor hanya menulis `lokal-*`. Untuk katalog: `InstallPortalTheme::verify()` dan `FetchThemeCatalog::isValid()` menolak `lokal-*`, sehingga katalog tidak pernah menimpa hasil edit. `ListPortalThemes` memberi `source = 'local'` dan Situs > Tampilan serta Katalog tema menampilkan label "Buatan sendiri".
- Setiap simpan menaikkan versi patch dan `theme.json` lama menjadi `previous.json`. Dengan begitu `RollbackPortalTheme`, `RemovePortalTheme` (halaman Katalog), dan aktivasi lewat `UpdatePortalTheme` berlaku tanpa jalur baru.
- Ekspor dilakukan lewat `php artisan pondok:theme-export lokal-<nama> <folder> --as=<slug>`. Perintah ini menulis folder paket dengan slug dan scope CSS yang diganti, lalu paket ditandatangani dengan `pondok:theme-sign --write-hashes`. Bentuknya perintah, bukan ZIP, karena penandatangannya hanya pemilik di mesinnya sendiri, `ext-zip` tidak dijamin ada di cPanel, dan cara ini tidak butuh route unduh.

**3. Pratinjau: URL bertanda tangan di route portal yang ada, dengan respons `private, no-store`.**
- Route portal stateless, jadi tidak bisa membaca login. Middleware baru `PreviewPortalTheme` di `$portalHeaders` (`routes/web.php`) bekerja seperti ini: bila ada query `pratinjau-tema=<slug>` dan tanda tangan URL sah (`URL::temporarySignedRoute`, 30 menit), request itu memakai slug tersebut. Responsnya diberi `Cache-Control: private, no-store` dan `X-Robots-Tag: noindex` setelah `SetPortalCacheHeaders` berjalan. Selain kondisi itu, middleware tidak melakukan apa pun.
- Editor membangun URL dari `APP_URL` (root yang sama dengan `ForcePortalRootUrl`), bukan dari host panel admin, supaya tanda tangannya cocok di portal.
- Slug efektif dibaca di tiga titik yang kini memakai `Setting::get('theme.template')`: `ResolveActiveTheme::resolve()` (±53), `GetPortalTheme::template()` (±90), dan `GetPortalTheme::handle()` (kunci `template`, ±158). Ketiganya mengambil dari satu sumber, dan `theme.template` tidak pernah ditulis. Palet, surface, dan font tema dari `GetPortalTheme::handle()` ikut tema pratinjau karena berasal dari `ResolveActiveTheme`.
- Aset dilayani `ShowThemeAsset` untuk tema terpasang mana pun, jadi tidak ada route baru. Editor memberi tautan ke `/`, `/berita`, `/galeri`, dan halaman statis pertama. Tautan di dalam halaman pratinjau kembali ke tema aktif.

**4. Antarmuka: satu halaman Filament `Site/ThemeEditor` di grup Situs.** Halaman ini memakai `getModule()` = `Module::Konten`, dan `canAccess()` mensyaratkan modul aktif serta `can('manage:themes')` (pola `ThemeCatalog`). Tidak ada permission atau migration baru. Isinya: pilih tema `lokal-*` atau aksi **Duplikat**; tiga `Select` enum; `ColorPicker` per token dengan rasio kontras tiap pasangan `assets.contrast` yang dihitung langsung (`BuildColorScale::contrast`, `live(onBlur)`); `Repeater` node beranda yang bisa diurutkan; tombol Simpan; dan tautan pratinjau. Hanya komponen bawaan Filament, jadi tanpa Node, antrean, atau proses latar. Simpan berjalan sinkron karena paket maksimal 1,5 MB.

**5. Keamanan: input sempit, lalu validator yang sama dengan tema resmi.** Pengguna hanya mengirim `#rrggbb`, enum, dan node. Tidak ada `FileUpload`, dan tidak ada byte CSS atau font dari pengguna. Token diganti dengan menyunting teks deklarasi `--nama: #xxxxxx;` di blok scope; nama token harus ada di hasil parse. Setelah itu paket divalidasi ulang penuh di staging oleh `ValidatePortalTheme` dan `ValidateThemePackage` (CSS, kontras, font, gerak). Kalau gagal, tema terpasang tidak berubah. Rasio kontras WCAG diperingatkan di form, dan pasangan di bawah 4,5:1 (3:1 untuk `large`) tetap ditolak (`contrast_low`). Font hanya yang ada di paket sumber, dengan lisensi yang sudah dibatasi validator ke OFL-1.1, Apache-2.0, atau CC0-1.0. Penulisan diserialkan `withWriteLock`, dan slug divalidasi lebih dulu (`assertThemeSlug`). Aktivitas `konten` dicatat dengan event `duplicated` dan `edited`.

**6. Kompatibilitas kait F105–F108:** Markup, `PARTS`, dan selektor tidak berubah. Duplikat hanya mengganti `body[data-pt-theme="<sumber>"]` dengan slug baru (AC-12). Risiko dan urutan kerja ada di bagian berikut.

## File
- **Baru:** `Actions/Konten/DuplicatePortalTheme.php` (operasi membuat tema lokal; punya static `rescope()` yang juga dipakai ekspor) dan `Actions/Konten/SaveEditedTheme.php` (operasi menyimpan suntingan). Keduanya Action terpisah karena operasi, masukan, dan aturannya berbeda. Juga baru: `Http/Middleware/PreviewPortalTheme.php`, `Filament/Pages/Site/ThemeEditor.php` beserta view, `Console/Commands/ThemeExportCommand.php`, dan `lang/id` (`theme_editor.*`).
- **Diubah:** `InstallPortalTheme` (pindah ke trait, tolak `lokal-*`), `Concerns/WritesInstalledThemes`, `FetchThemeCatalog` (saring `lokal-*`), `ListPortalThemes` (`source` `local`), `Pages/Site/Theme.php` dan `ThemeCatalog.php` (label), `ResolveActiveTheme`, `GetPortalTheme`, dan `routes/web.php`.
- **Dipakai ulang tanpa diubah:** `ValidatePortalTheme`, `ValidateThemePackage`, `ValidateThemeCss`, `ThemeComponents`, `ThemeStyleMap`, `RollbackPortalTheme`, `RemovePortalTheme`, `UpdatePortalTheme`, `ShowThemeAsset`, `BuildColorScale`, dan `ThemeSignCommand`.

## Kriteria penerimaan
- **AC-1 Akses:** `ThemeEditor` hanya untuk `manage:themes` dengan modul Konten aktif. Peran lain atau modul mati: tidak terlihat atau 403. `ModuleComplianceTest` hijau.
- **AC-2 Duplikat tema terpasang ber-`assets`:** menghasilkan `lokal-<nama>` dengan `theme.json` valid (slug dan nama baru, versi `1.0.0`), CSS tanpa sisa `data-pt-theme="<sumber>"`, font byte-identik, serta sha256/size yang cocok. Tema itu valid di `ListPortalThemes` dengan `source = local`, dan tema sumber tetap byte-identik. Duplikat tema resmi (`resources/themes`) dan tema tanpa `assets` hanya menghasilkan `theme.json`.
- **AC-3 Penolakan duplikat:** `InvalidArgumentException` tanpa folder atau `_staging` yang tersisa bila slug target sudah ada, sumber tidak valid, nama kosong atau terlalu panjang, atau ada kunci masukan yang tidak dikenal.
- **AC-4 Simpan token:** hanya nilai token yang diminta berubah, dan sisa CSS byte-identik. Nama token tak dikenal, token bukan hex, nilai bukan `#rrggbb`, atau kunci masukan tak dikenal ditolak. Halaman tidak memuat komponen unggah.
- **AC-5 Kontras:** token yang membuat pasangan `assets.contrast` di bawah ambang ditolak, dan tema terpasang tidak berubah. Form menampilkan rasio tiap pasangan sebelum simpan.
- **AC-6 Layout:** urutan, tambah/hapus, varian, dan style skalar node puncak tersimpan, `children` dipertahankan, dan `header`/`footer` tidak berubah. Komponen, varian, atau style di luar `ThemeComponents`/`ThemeStyleMap` ditolak, begitu juga style responsif (array).
- **AC-7 Riwayat:** setiap simpan menaikkan versi patch dan versi lama menjadi `previous.json`. `RollbackPortalTheme` bisa mengembalikannya. Simpan yang gagal tidak mengubah `theme.json` maupun aset. Log `edited` mencatat pelaku.
- **AC-8 Batas lokal:** `SaveEditedTheme` menolak slug tanpa `lokal-`, tema resmi, dan tema yang sedang aktif (`theme.template`). `InstallPortalTheme` menolak entri berslug `lokal-*` sebelum menulis apa pun, dan `FetchThemeCatalog` membuangnya. Test pasang, kembalikan, hapus, dan katalog yang sudah ada tetap hijau.
- **AC-9 Pratinjau sah:** URL bertanda tangan pada `/`, `/berita`, `/galeri`, dan halaman statis merender tema lokal (`data-pt-theme="lokal-…"`, `<link>` ke asetnya) dengan header `private, no-store` dan noindex, dan `theme.template` tidak berubah. URL yang dibuat ketika request admin memakai host berbeda dari `APP_URL` tetap sah di portal.
- **AC-10 Pratinjau ditolak:** tanda tangan salah atau kedaluwarsa, atau slug tidak terpasang atau tidak valid, merender tema aktif dengan header cache publik biasa, tanpa error. Request tanpa query tidak berubah (`PortalThemeGoldenTest` hijau).
- **AC-11 Ekspor:** `pondok:theme-export` menulis paket dengan slug `--as` yang lolos `ValidateThemePackage` setelah `pondok:theme-sign --write-hashes`. Perintah menolak `--as` berawalan `lokal-`, slug tidak sah, dan folder tujuan yang sudah ada.
- **AC-12 Kompatibilitas:** HTML beranda pratinjau duplikat sama dengan HTML beranda tema sumber yang aktif, kecuali slug di `data-pt-theme` dan URL CSS. `<style data-portal-theme>` (palet, surface, font dari `GetPortalTheme::handle()`) identik untuk duplikat tema tanpa CSS yang ber-`palette`/`font`. `ThemeMarkupContractTest` dan `ThemeMarkupHooksTest` hijau.
- **AC-13 Label:** tema `lokal-*` berlabel "Buatan sendiri" di Situs > Tampilan dan Katalog tema.
- **AC-14 Tampilan:** halaman editor lulus `docs/design-guide.md` §0 di 360px, 1024px, dan desktop (dicek manual oleh sesi utama), dan semua teks berasal dari `lang/id`.

## Rencana test
- `Actions/Konten/DuplicatePortalThemeTest` (AC-2, 3), `SaveEditedThemeTest` (AC-4 sampai 8), serta kasus tambahan di `InstallPortalThemeTest` dan `FetchThemeCatalogTest` (AC-8).
- `Http/Portal/ThemePreviewTest` (AC-9, 10, 12), `Filament/Pages/ThemeEditorPageTest` (AC-1, 5, 13), dan `Console/ThemeExportCommandTest` (AC-11).
- Satu helper fixture bersama di `tests/Pest.php` (memperluas `themePkg`): paket ber-CSS kecil dengan dua token hex, satu pasangan kontras, dan satu font `wOF2` palsu. Tidak memakai tema dari repo privat.

## Paket kerja: PR bertahap ke `dev`
Setiap PR berdiri sendiri, lulus test terdampak, Pint, dan suite penuh, lalu di-merge berurutan. Urutan RED → GREEN → VERIFY berlaku per PR.
- **PR1 = P1, refactor trait tanpa perubahan perilaku.** Pindahkan `stageAssets`, `swap`, dan `placeAssets` ke `WritesInstalledThemes`. Selesai bila semua test pasang, kembalikan, hapus, dan adversarial tema hijau tanpa test diubah. Tolak `lokal-*` di `InstallPortalTheme`/`FetchThemeCatalog` (AC-8 bagian katalog) masuk di sini sebagai satu commit terpisah.
- **PR2 = P2 + P3, Action dan label.** `DuplicatePortalTheme`, `SaveEditedTheme`, `source` `local`. Selesai: AC-2 sampai 8 hijau.
- **PR3 = P4, pratinjau.** Middleware, slug efektif di tiga titik baca, dan route. Selesai: AC-9, 10, 12 hijau dan golden tidak berubah.
- **PR4 = P5, halaman.** `ThemeEditor`, label di Tampilan dan Katalog, `lang/id`, dan `/anti-ui-slop`. Selesai: AC-1, 5, 13, 14.
- **PR5 = P6, ekspor.** `pondok:theme-export`. Selesai: AC-11.
- Fitur dianggap tuntas (`done`) setelah PR5 masuk `dev`.

## Di luar ruang lingkup
Penjualan, lisensi, dan pembeda gratis/berbayar; penerbitan ke pondok.org, katalog, `main`, atau rilis; editor visual drag-and-drop dan iframe pratinjau langsung; tema pihak luar dan unggah paket; editor CSS bebas, font baru, header/footer, `children`, dan style responsif; serta celah 7 dan 8 di `CELAH-SISTEM.md`.

## Risiko
- **Cakupan penuh berarti banyak UI dan test.** Mitigasinya: PR bertahap yang masing-masing lulus suite, satu helper fixture bersama, `style` dibatasi ke nilai skalar dari `ThemeStyleMap::MAP` (tanpa editor responsif), dan form yang hanya memakai komponen bawaan Filament.
- **Refactor PR1 menyentuh jalur pasang tema resmi.** PR1 tidak mengubah perilaku dan dijaga test yang sudah ada, dan di-merge sebelum fitur baru apa pun.
- **Penyuntingan teks CSS bisa salah tempat** (nilai di dalam komentar atau string). Penyuntingan hanya menyentuh deklarasi di blok scope yang cocok dengan hasil parse, lalu validasi ulang penuh. AC-4 memastikan sisa CSS byte-identik.
- **Hex literal di luar token tidak ikut berubah**, sehingga palet bisa tampak setengah berganti. Ini diterima di v0, dicatat di UI, dan PANDUAN akan menganjurkan `var()` untuk semua warna.
- **Urutan middleware yang salah bisa membuat pratinjau ter-cache publik.** Dijaga AC-9 dan AC-10 yang memeriksa header persis.
- **Tautan pratinjau yang bocor** memperlihatkan draf paling lama 30 menit. Dampaknya kecil karena tema bukan data pribadi, dan aset tema terpasang memang sudah publik di `/tema/…`.

## Keputusan
- **Sudah diambil:** menyunting tema yang sedang aktif ditolak (AC-8). Alurnya duplikat, sunting, pratinjau, lalu aktifkan.
- **Keputusan pemilik:** cakupan v0 penuh, yaitu token warna, enum, susunan beranda dengan tambah/hapus dan style skalar, pratinjau, dan ekspor. Dikonfirmasi 7 Nov 2026.
- Saklar akses tambahan tidak dibuat (YAGNI): editor hanya ada di `dev`, dan pembatasannya diputuskan ketika tema dirilis ke `main`.

## Review #1 (plan-reviewer): CHANGES_REQUESTED, 0 blocker, 1 major, 6 minor
- **Major (ukuran):** teks dan AC dipadatkan (14 AC), cakupan tidak dipangkas sesuai keputusan pemilik, paket kerja menjadi PR bertahap dengan kriteria selesai, dan risiko cakupan beserta mitigasinya ditambahkan.
- **Minor 2:** tiga titik baca slug (`GetPortalTheme::template()`/`handle()`) ditulis di bagian 3, dan AC-12 kini memeriksa `<style data-portal-theme>`.
- **Minor 3:** K1 dihapus, K2 menjadi keputusan yang sudah diambil, dan K3 menjadi keputusan pemilik.
- **Minor 4:** sumber byte duplikat (`assets/<hash12>` milik `theme.json` sumber; tema resmi dan tema tanpa `assets` hanya `theme.json`) dinyatakan, dan AC-2 disesuaikan.
- **Minor 5:** URL pratinjau dibangun dari `APP_URL`, dan AC-9 mendapat kasus host admin yang berbeda.
- **Minor 6:** label "Buatan sendiri" dan penyaringan `lokal-*` di `FetchThemeCatalog` ditambahkan (File, AC-8, AC-13).
- **Minor 7:** catatan ketergantungan F105 yang keliru dihapus. `feature_list.json` sudah dibetulkan orkestrator.

## Riwayat revisi
- 7 Nov 2026: draf awal.
- 7 Nov 2026, revisi 1: menjawab Review #1.
