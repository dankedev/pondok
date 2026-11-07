# F110 konten-editor-tema — Editor tema v0 (rencana)

Issue: #152
Status rencana: Draf, menunggu review plan-reviewer
Jalur penuh (menulis berkas tema terpasang, menyentuh `InstallPortalTheme`, route portal yang di-cache publik, dan hak akses). PR implementasi kelak ke `dev` dengan `Refs #152`; tidak ke `main`, katalog, atau pondok-web.
Bergantung pada F102 sampai F109 (semua `done`). Sumber: `pondok-themes-work/PANDUAN-MEMBUAT-TEMA.md`, `CATATAN-DISTRIBUSI-TEMA.md`, `CELAH-SISTEM.md`, `LAPORAN-F109.md` (repo privat).

## Ringkasan & tujuan
Kini tema hanya bisa dibuat dengan menulis JSON dan CSS tangan lalu `install-theme.sh` (kunci sekali pakai). Editor v0 memberi super-admin cara cepat membuat variasi dari tema yang sudah lolos validasi: **duplikat, ubah token warna dan susunan beranda, pratinjau, lalu aktifkan lewat Situs > Tampilan**. Tidak ada format paket baru: hasilnya tema terpasang biasa di `storage/app/themes/lokal-<nama>/`, diperiksa validator yang sama dengan tema resmi.

## Jawaban enam pertanyaan issue

**1. Yang diedit di v0: duplikat tema lalu ubah token dan susunan, BUKAN editor CSS.**
- Duplikat tema valid apa pun dari `ListPortalThemes` (resmi atau terpasang, ber-CSS atau tidak) menjadi `lokal-<nama>`.
- Yang bisa diubah: (a) `name`, `description`; (b) enum `palette`, `surface`, `font` (`ValidatePortalTheme::assertLook`), satu-satunya pengubah tampilan untuk tema tanpa CSS (Amanah, Lapang); (c) **nilai custom property `--*` berliteral `#rrggbb` di blok scope tingkat atas** `body[data-pt-theme="<slug>"] { ... }`. Daftarnya dibaca generik dari keluaran `ValidateThemeCss::handle()` (`rules[top && selectors === [scope]]`), karena tiap tema memberi nama tokennya sendiri (Tegas `--pt-ink`/`--pt-paper`, Cahaya 11 token hex, Kobalt 7, Mihrab 9); (d) node puncak `home.layout`: urutan, tambah/hapus, `variant` (`ThemeComponents::VARIANTS`), dan `style` skalar (`tone`, `align`, `width`, `radius`, `shadow`, `border`, `gap`, `padding.base`; `ThemeStyleMap::MAP`). Sudut, bayangan, dan jarak per blok datang dari (d), bukan dari CSS.
- Alasan: empat tema ber-CSS sudah memusatkan warna di custom property blok scope (aturan 6 PANDUAN), dan validator kontras sudah membaca persis blok itu. Mengganti nilai di sana memberi palet baru di seluruh tema tanpa menulis satu aturan CSS. Editor CSS bebas sama dengan pekerjaan yang sekarang sudah dilakukan di repo tema; nilainya kecil untuk v0 dan permukaan risikonya terbesar.
- Ditunda: editor CSS bebas (v1 bila v0 kurang, lewat `ValidateThemeCss`); token non-warna (sudut/bayangan/jarak di CSS belum dijadikan custom property oleh tema mana pun, jadi perlu diubah dulu di tema sumber); ganti/tambah font; header dan footer; `children` node `container`/`columns` (dipertahankan apa adanya); `blocks` legacy (tetap ikut bila tema sumbernya memakai itu, tidak bisa ditambah).

**2. Penyimpanan dan pemasangan: tema terpasang biasa berawalan `lokal-`, lewat jalur tulis yang sama dengan `InstallPortalTheme`.**
- `InstallPortalTheme::stageAssets()` dan `swap()` (staging, `ValidateThemePackage`, folder `assets/<hash12>`, `previous.json`, pangkas aset) dipindah ke trait `Concerns\WritesInstalledThemes` yang sudah dipakai pasang/kembalikan/hapus. Tanda tangan, sha256 katalog, dan aturan "versi harus naik" tetap hanya di `InstallPortalTheme::verify()`/`handle()`.
- Tema buatan sendiri dibedakan dengan slug berawalan `lokal-` (slug tetap lolos `ResolveThemePath::SLUG_PATTERN`, sisa nama maks. 34 karakter). Editor hanya menulis slug `lokal-*`; `InstallPortalTheme` menolak entri katalog berslug `lokal-*`, jadi pembaruan katalog tidak pernah menimpa hasil edit, dan editor tidak pernah menyentuh tema resmi/katalog.
- Tiap simpan menaikkan versi patch dan `theme.json` lama menjadi `previous.json`, sehingga **Kembalikan** (`RollbackPortalTheme`) dan **Hapus** (`RemovePortalTheme`) di halaman Katalog tema langsung berlaku untuk tema lokal. Aktivasi tetap lewat Situs > Tampilan (`UpdatePortalTheme`), tanpa jalur baru.
- Ekspor untuk ditandatangani: perintah `php artisan pondok:theme-export lokal-<nama> <folder> --as=<slug-resmi>` menulis folder paket (slug dan scope CSS diganti) yang lalu ditandatangani dengan `pondok:theme-sign --write-hashes` seperti biasa. Perintah, bukan unduhan ZIP: penandatangan hanya pemilik di mesinnya sendiri, `ext-zip` tidak dijamin di cPanel, dan tidak perlu route unduh baru.

**3. Pratinjau: URL bertanda tangan pada route portal yang sudah ada, respons `private, no-store`.**
- Route portal stateless (tanpa sesi), jadi pratinjau tidak bisa memakai login. Middleware baru `PreviewPortalTheme` di grup `$portalHeaders` (`routes/web.php`): bila query `pratinjau-tema=<slug>` ada DAN tanda tangan URL sah (`URL::temporarySignedRoute`, 30 menit, kunci `APP_KEY`), `ResolveActiveTheme` (scoped) memakai slug itu untuk request ini, dan respons diberi `Cache-Control: private, no-store` serta `X-Robots-Tag: noindex` (dijalankan setelah `SetPortalCacheHeaders`). Selain itu middleware tidak berbuat apa pun.
- Slug pratinjau dibaca di dua titik yang sekarang memakai `Setting::get('theme.template')`: `ResolveActiveTheme::resolve()` dan `GetPortalTheme::handle()` (kunci `template`). Keduanya membaca slug efektif dari satu tempat; `theme.template` tidak pernah ditulis.
- CSS dan font sudah dilayani `ShowThemeAsset` untuk tema terpasang mana pun (`ResolveThemeAssetPath`), jadi tidak ada route baru. Editor menampilkan tautan pratinjau untuk beranda, `/berita`, `/galeri`, dan halaman statis pertama. Tautan di dalam halaman pratinjau kembali ke tema aktif (dicatat di UI).
- Ditolak: query unik tanpa tanda tangan (siapa pun bisa merender draf dan memecah cache) dan route admin yang merender ulang view portal (menggandakan pemanggilan controller portal).

**4. Antarmuka: satu halaman Filament `Site/ThemeEditor` di grup navigasi Situs.**
- `getModule()` = `Module::Konten`, `canAccess()` = modul Konten aktif DAN `can('manage:themes')` (pola `ThemeCatalog`; permission sudah ada dan hanya milik super-admin). Tanpa permission, peran, atau migration baru.
- Isi: pilih tema `lokal-*` atau aksi **Duplikat** (sumber + nama); form: teks, tiga `Select` enum, `ColorPicker` per token hex dengan rasio kontras tiap pasangan `assets.contrast` dihitung langsung (`BuildColorScale::contrast`, `->live(onBlur: true)`), `Repeater` node beranda (bawaan Filament, bisa diurutkan, opsi dari `ThemeComponents`/`ThemeStyleMap`), tombol Simpan dan tautan pratinjau.
- Hanya komponen Filament bawaan: asetnya sudah dibangun saat rilis, tanpa Node, Vite, atau proses latar saat runtime. Simpan sinkron (paket ≤ 1,5 MB, tanpa antrean).

**5. Keamanan dan validasi: input sempit, lalu validator yang sama dengan tema resmi.**
- Pengguna hanya mengirim nilai `#rrggbb`, nama enum, dan daftar node. Tidak ada `FileUpload`, tidak ada byte CSS atau font dari pengguna. CSS dan font duplikat disalin dari paket sumber yang sudah lolos pemasangan, lalu diperiksa ulang.
- Penggantian token menyunting teks CSS hanya pada deklarasi `--nama: #xxxxxx;` di blok scope (nama harus ada di daftar token hex hasil parse); paket lalu diulang penuh oleh `ValidatePortalTheme` + `ValidateThemePackage` (`ValidateThemeCss`, kontras, font, gerak) di staging. Gagal = ditolak, tema terpasang tidak berubah (fail-closed).
- Kontras WCAG: peringatan di form sebelum simpan; pasangan terdeklarasi di bawah 4,5:1 (3:1 `large`) tetap DITOLAK `ValidateThemePackage` (`contrast_low`). Font: hanya font paket sumber (lisensinya sudah dibatasi `ValidatePortalTheme` ke `OFL-1.1`/`Apache-2.0`/`CC0-1.0`; keempat tema resmi memakai OFL); tidak ada font baru.
- Tulis per slug diserialkan `withWriteLock`; slug divalidasi sebelum path dibentuk (`assertThemeSlug`). Log `activity('konten')` event `edited`/`duplicated` dengan pelaku.

**6. Risiko, kompatibilitas kait F105 sampai F108, dan urutan paket kerja:** lihat bagian Risiko dan Paket kerja. Kompatibilitas: editor tidak mengubah markup, `PARTS`, atau selektor CSS; duplikat hanya mengganti token scope `body[data-pt-theme="<sumber>"]` menjadi slug baru, jadi tema lokal memakai kait yang sama persis dengan sumbernya (AC-13).

## File
- **Baru** `app/Actions/Konten/DuplicatePortalTheme.php`: operasi "buat tema lokal dari tema valid" (baca sumber, ganti slug/nama/scope, versi `1.0.0`, tulis lewat trait). Statis `rescope(css, from, to)` dipakai juga oleh ekspor.
- **Baru** `app/Actions/Konten/SaveEditedTheme.php`: operasi "simpan suntingan tema lokal" (teks, enum, token, layout; naik versi patch; tulis lewat trait). Dua Action karena dua operasi berbeda masukan dan aturan (CLAUDE.md: satu Action per operasi).
- **Baru** `app/Http/Middleware/PreviewPortalTheme.php`, `app/Filament/Pages/Site/ThemeEditor.php` + view, `app/Console/Commands/ThemeExportCommand.php`, kunci `lang/id/pondok.php` (`theme_editor.*`).
- **Diubah** `InstallPortalTheme` (pindah `stageAssets`/`swap`/`placeAssets` ke trait; tolak `lokal-*`), `Concerns/WritesInstalledThemes`, `ResolveActiveTheme` + `GetPortalTheme` (slug pratinjau), `routes/web.php` (`$portalHeaders`).
- **Dipakai ulang tanpa diubah**: `ValidatePortalTheme`, `ValidateThemePackage`, `ValidateThemeCss`, `ThemeComponents`, `ThemeStyleMap`, `ListPortalThemes`, `RollbackPortalTheme`, `RemovePortalTheme`, `UpdatePortalTheme`, `ShowThemeAsset`, `BuildColorScale`, `ThemeSignCommand`. `MigrateThemeSchema`, `FetchThemeCatalog`, `InstallThemeFromCatalog` tidak disentuh.

## Kriteria penerimaan
- **AC-1** Akses: `ThemeEditor` hanya untuk pemegang `manage:themes` dengan modul Konten aktif; peran lain dan modul mati = tidak terlihat/403. `ModuleComplianceTest` hijau.
- **AC-2** Duplikat tema ber-CSS: folder `lokal-<nama>` berisi `theme.json` valid (slug/nama baru, versi `1.0.0`), CSS tanpa sisa `data-pt-theme="<sumber>"`, font byte-identik, sha256/size `assets` cocok; muncul valid di `ListPortalThemes`; tema sumber tidak berubah (byte). Duplikat tema tanpa CSS juga berhasil.
- **AC-3** Duplikat ditolak (`InvalidArgumentException`, tanpa folder atau `_staging` tersisa) untuk: slug target sudah ada, sumber tak valid, nama kosong/terlalu panjang.
- **AC-4** Simpan token mengganti hanya nilai custom property yang diminta di blok scope; sisa CSS byte-identik. Nama token tak dikenal, token bukan hex, atau nilai bukan `#rrggbb` ditolak.
- **AC-5** Token yang membuat pasangan `assets.contrast` di bawah ambang ditolak dengan pesan kontras dan tema terpasang tidak berubah; form menampilkan rasio tiap pasangan sebelum simpan.
- **AC-6** Simpan layout: urutan, varian, dan style node puncak `home.layout` tersimpan; `children` dipertahankan; komponen/varian/style di luar `ThemeComponents`/`ThemeStyleMap` ditolak; `header`/`footer` tidak berubah.
- **AC-7** Setiap simpan menaikkan versi patch, versi lama menjadi `previous.json`, dan `RollbackPortalTheme` mengembalikannya; simpan yang gagal tidak mengubah `theme.json` maupun folder aset; log `edited` mencatat pelaku.
- **AC-8** Batas lokal: `SaveEditedTheme` menolak slug tanpa `lokal-`, tema resmi, dan tema yang sedang aktif (`theme.template`); `InstallPortalTheme` menolak entri katalog berslug `lokal-*` sebelum menulis apa pun; test pasang/kembalikan/hapus yang ada tetap hijau.
- **AC-9** Pratinjau: URL bertanda tangan sah pada `/`, `/berita`, `/galeri`, dan halaman statis merender tema lokal (`data-pt-theme="lokal-…"`, `<link>` ke aset slug itu) dengan `Cache-Control: private, no-store` dan `X-Robots-Tag: noindex`; `theme.template` tidak berubah.
- **AC-10** Tanda tangan salah, kedaluwarsa, atau slug tak terpasang/tak valid: halaman dirender dengan tema aktif dan header cache publik biasa, tanpa error. Request tanpa query tidak berubah (`PortalThemeGoldenTest` hijau).
- **AC-11** Tidak ada unggahan: `SaveEditedTheme`/`DuplicatePortalTheme` menolak kunci masukan tak dikenal; halaman tidak memuat komponen unggah berkas.
- **AC-12** `pondok:theme-export` menulis folder paket dengan slug `--as` (CSS di-scope ulang) yang lolos `ValidateThemePackage` setelah `pondok:theme-sign --write-hashes`; menolak `--as` berawalan `lokal-`, slug tak sah, dan folder tujuan yang sudah ada (tidak menimpa).
- **AC-13** Kompatibilitas kait: HTML beranda pratinjau tema duplikat sama dengan HTML beranda tema sumber yang aktif, kecuali slug pada `data-pt-theme` dan URL CSS; `ThemeMarkupContractTest` dan `ThemeMarkupHooksTest` hijau.
- **AC-14** Tampilan halaman lulus checklist `docs/design-guide.md` §0 di 360px, 1024px, dan desktop; semua teks dari `lang/id`.

## Rencana test
- `tests/Feature/Actions/Konten/DuplicatePortalThemeTest.php` (AC-2, 3, 11), `SaveEditedThemeTest.php` (AC-4 sampai 8, 11), tambahan satu kasus `lokal-*` di `InstallPortalThemeTest.php` (AC-8).
- `tests/Feature/Http/Portal/ThemePreviewTest.php` (AC-9, 10, 13): pakai helper paket tema yang ada (`themePkg`, `portalSeedContent`).
- `tests/Feature/Filament/Pages/ThemeEditorPageTest.php` (AC-1, 5 rasio di form), `tests/Feature/Console/ThemeExportCommandTest.php` (AC-12). AC-14 manual oleh sesi utama.
- Fixture: paket ber-CSS kecil buatan test (dua token hex + satu pasangan kontras + satu font `wOF2` palsu), bukan tema dari repo privat.

## Paket kerja (RED lalu GREEN, berurutan)
- **P1 Trait tulis bersama (hijau: AC-8 bagian katalog; regresi pasang/kembalikan/hapus).** Pindah `stageAssets`/`swap`/`placeAssets` ke `WritesInstalledThemes` tanpa ubah perilaku; tolak `lokal-*` di `InstallPortalTheme::verify()`.
- **P2 `DuplicatePortalTheme` (AC-2, 3, 11 sebagian).**
- **P3 `SaveEditedTheme` (AC-4 sampai 8, 11).**
- **P4 Pratinjau (AC-9, 10, 13):** middleware, slug efektif di `ResolveActiveTheme`/`GetPortalTheme`, route.
- **P5 Halaman `ThemeEditor` + `lang/id` (AC-1, 5, 14).** Panggil `/anti-ui-slop` bila tersedia.
- **P6 `pondok:theme-export` (AC-12).** Terakhir karena hanya dibutuhkan saat tema lokal mau dijadikan resmi.

## Di luar ruang lingkup
Penjualan, lisensi, atau pembeda gratis/berbayar; penerbitan ke pondok.org, katalog, `main`, atau rilis; editor visual drag-and-drop dan pratinjau langsung di dalam form (iframe hidup); tema pihak luar atau unggah paket; editor CSS bebas, font baru, header/footer, `children`; celah terbuka `CELAH-SISTEM.md` butir 7 dan 8.

## Risiko
- **Refactor `swap` di P1** menyentuh jalur pasang tema resmi yang sudah dirilis ke `dev`: dikerjakan tanpa perubahan perilaku, dijaga test pasang/kembalikan/hapus dan adversarial yang ada; P1 berdiri sendiri sebelum fitur baru.
- **Penyuntingan teks CSS** bisa salah tempat (mis. nilai dalam komentar atau string). Mitigasi: hanya deklarasi di blok scope pertama yang cocok dengan hasil parse, lalu validasi ulang penuh; AC-4 memeriksa sisa CSS byte-identik.
- **Warna literal di luar token**: CSS yang menulis hex langsung di aturan lain tidak ikut berubah, jadi palet bisa tampak setengah berganti. Diterima di v0; dicatat di UI dan di PANDUAN (pakai `var()` untuk semua warna).
- **Header cache**: bila urutan middleware salah, halaman pratinjau bisa ter-cache publik. Dijaga AC-9/10 yang memeriksa header persis.
- **Tautan pratinjau bocor**: siapa pun yang memegangnya melihat draf maksimal 30 menit. Dampak kecil (tema bukan data pribadi); CSS tema terpasang memang sudah bisa diambil lewat `/tema/<slug>/<hash12>/`.
- **Variasi kurang terasa**: warna + susunan mungkin belum cukup "benar-benar berbeda". Lihat K3.

## Keputusan terbuka untuk pemilik
- **K1 Siapa yang melihat editor.** `manage:themes` dimiliki super-admin setiap sekolah, jadi kode di `dev` akan membuka editor untuk semua super-admin begitu dirilis. Rekomendasi: tambah saklar `config('pondok.theme_editor')` (env `PONDOK_THEME_EDITOR`, bawaan `false`) di `canAccess()` dan di Action, menyala hanya di mesin pemilik sampai editor jadi produk.
- **K2 Menyunting tema yang sedang aktif.** Rekomendasi: ditolak (AC-8). Alurnya duplikat, sunting, pratinjau, lalu aktifkan, sehingga pengunjung tidak pernah melihat setengah jadi. Alternatif: boleh dengan peringatan.
- **K3 Cukupkah warna + susunan untuk v0.** Rekomendasi: ya untuk v0; variasi besar tetap datang dari empat CSS dasar yang berbeda, editor memperbanyaknya. Bila belum cukup setelah dicoba, v1 = editor CSS bebas lewat `ValidateThemeCss` (rencana terpisah).

## Riwayat revisi
- 7 Nov 2026: draf awal.
