# F102 konten-katalog-tema — Tema portal berbasis JSON: renderer bawaan dan katalog resmi

Issue: #133
Status rencana: APPROVED (setelah revisi 1)
PR ditujukan ke `dev` (cabang `feat/f102-katalog-tema`), memakai `Refs #133`, bukan `Closes`.
Menggantikan rencana lama (tema paket Blade + zip); versi lama ada di riwayat git dan tidak berlaku lagi.

## Ringkasan & tujuan
Tema = JSON. Inti punya renderer dan mesin gaya PHP; tema tidak membawa Blade/PHP/JS. Sekolah memilih tema dan menyetel token F070; data konten tetap milik sekolah (tema hanya struktur dan gaya). Cakupan: header, footer, bagian beranda. Builder (pondok.org) bukan F102.

## Pemecahan: DUA issue/PR
- **T1** (issue #133, `Refs #133`): skema + validator, renderer, mesin gaya, tema resmi `default`/`centered`/`klasik`, pemilihan, fallback. Tanpa jaringan, nilai mandiri.
- **T2** (issue baru, usul `F103`, `depends_on: F102`, direview sendiri): katalog, unduh, tanda tangan, pasang/kembalikan, doctor. Alasan: diblokir keputusan URL katalog + pemegang kunci, menambah kripto/jaringan/antrean/`ext-sodium`, dan skema harus matang di T1 dulu. Rencana memuat keduanya (AC bertanda T1/T2) agar skema dan kontrak dirancang utuh; paket T2 dikerjakan setelah issue-nya ada (dibuat orkestrator setelah user setuju).

## Ruang lingkup
- Termasuk T1: skema v1, validator, renderer, mesin gaya, 3 tema resmi, `ListPortalThemes` menggantikan `GetPortalTheme::TEMPLATES`, header/footer inti jadi partial, fallback.
- Termasuk T2: katalog statis, unduh via antrean, verifikasi tanda tangan, pasang atomik, rollback satu versi, doctor, perintah penandatangan.
- Tidak termasuk: builder, penyuntingan struktur oleh sekolah, JS/HTML/CSS bebas dari tema, nilai warna bebas, **aset gambar/font tema** (lihat Pertanyaan 2), tema berbayar, pihak ketiga, marketplace, tema untuk halaman isi/halaman bersesi, rotasi kunci, statistik.

## Skema v1 (`theme.json`), set kunci tertutup
`schema_version` (int), `slug`, `name`, `description`, `version` (semver), `min_core`, `header?`, `footer?`, `home.sections.<tipe_blok>?`. Tanpa `assets` (opsi A).
- **Node:** `{variant?, style?, children?}` plus `component` untuk header/footer. `children` hanya pada `container`. Pada `home.sections`, kunci = tipe blok dan `component` DITURUNKAN dari pemetaan di bawah, bukan ditulis di node (tanpa redundansi).
- **Pustaka 12 komponen:** `container`, `brand`, `nav_menu` (header); `footer_widgets`, `copyright` (footer); beranda `hero`, `text_image`, `feature_grid`, `quote`, `cta`, `video`, `feed_list`. Slot sah dan daftar `variant` per komponen ada di satu peta tetap (`ThemeComponents`), dibatasi pada yang DIPAKAI tiga tema resmi; komponen di slot salah ditolak.
- **Gaya = enum:** `tone` (`auto|light|muted|brand|dark`), `padding`, `gap`, `align`, `width`, `radius`, `shadow`, `border`, `columns` (2-4), varian responsif `{base, md, lg}`; hanya nilai yang dipakai tema resmi. Tidak ada warna, url, font-family, atau string bebas.
- **Batas:** berkas ≤ 64 KB (dicek SEBELUM `json_decode`), `json_decode` dengan depth ≤ 8, UTF-8 rusak ditolak, kedalaman pohon ≤ 6, node ≤ 150, `slug` `^[a-z0-9-]{2,40}$` dan harus sama dengan nama direktori, kunci tak dikenal menolak.
- **Versi skema:** `schema_version` hanya naik untuk perubahan yang merusak; inti mendukung N dan N-1 lewat `MigrateThemeSchema` (dalam memori); tambahan kompatibel cukup menaikkan `min_core`. Di luar dukungan = fallback.
- **Pemetaan blok -> komponen:** `hero`, `text_image`, `features`->`feature_grid`, `quote`, `cta`, `video`, `feed`->`feed_list`. Data blok (`ParseContentBlocks`, `NormalizeContentBlocks`, `GetPortalHomeData`, `SanitizeBlockText`, `portal.home_blocks`) tidak berubah dan tidak dimigrasi. Tipe yang tidak dipetakan tema memakai komponen bawaan inti.

## Migrasi beranda (keputusan eksplisit)
- (a) `default` dan `centered` TIDAK memakai `style` di bagian beranda: varian = view statis `portal.theme.<komponen>` dengan kelas Tailwind lama (`default`->`split`, `centered`->`centered`); hanya `klasik` memakai mesin gaya. HTML default/centered tetap identik.
- (b) `tone: auto` = selang-seling latar dihitung renderer (menggantikan closure `$band()` di `home.blade.php`) berdasarkan urutan blok yang tampil.
- (c) Konteks view komponen: renderer mengoper `headingId` (id heading unik per blok), `band` (hasil selang-seling), dan `psbOpen` (dari `GetPortalHomeData`) sebagai variabel eksplisit; view tidak menghitung sendiri.
- (d) Golden AC-4 direkam SEBELUM refaktor dan dinormalisasi dari hash aset Vite serta spasi; pakai ulang pola `PortalTemplateTest` AC-11.

## Model data
Tanpa migration. `theme.template` (Setting, F072) berisi slug tema (`default`/`centered` lama tetap valid). Tema resmi: `resources/themes/<slug>/theme.json`. T2: terpasang di `storage/app/themes/<slug>/theme.json` (+ `_previous/`, `_staging/`), status unduh di `Setting` `themes.install.<slug>`.
- **Kontrak T1->T2:** `ListPortalThemes` = satu daftar bersumber ganda (resmi + terpasang). Tema resmi menang; slug terpasang yang sama dengan slug resmi ditolak; slug manifest harus sama dengan nama direktori.

## Action class (`app/Actions/Konten/`)
- **T1:** `ValidatePortalTheme` (skema, batas, whitelist; kembalikan pohon ternormalisasi atau galat), `ListPortalThemes`, `ResolveActiveTheme` (tema aktif valid atau fallback inti), `BuildThemeStyles` (enum -> deklarasi dari peta server pada kelas `t-<hash>-<n>`; sisip `<style data-theme-layout>` sebelum `<style data-portal-theme>`).
- **Tanpa Cache:** validasi dan CSS cukup dimemoisasi per request (properti statis/instance). Alasan: cache bawaan `database` = query tambahan per request, melanggar "tanpa query tambahan". Biaya: membaca 1 berkas ≤ 64 KB + validasi O(≤150 node) per request; request portal sudah di balik cache HTTP publik (AC-7). Query tambahan: 0 (nilai `theme.template` sudah ada di Setting yang dimuat F070). `Log::warning` dibatasi satu kali per slug per proses lewat penanda statis, tanpa `Cache::add`.
- Renderer: komponen Blade rekursif `<x-portal.theme-node>`; `component` hanya dipetakan lewat peta tetap ke view inti (tidak pernah `@include` dari nilai JSON). `layouts/public` menghitung `$school`, `$navigation`, `$footer` sekali. Header/footer inti dipindah ke `portal.partials.header|footer` (jadi fallback).
- `GetPortalTheme` tetap untuk token F070; `TEMPLATES` dan `blockView` dihapus. `UpdatePortalTheme` menerima slug dari `ListPortalThemes` yang valid.
- **T2:** `ThemeCatalogClient` (satu-satunya pemakai `Http::`), `InstallThemeFromCatalog` (job), `InstallPortalTheme`, `RollbackPortalTheme`, `RemovePortalTheme`, `pondok:theme-sign`. Keamanan (ringkas): (a) tanda tangan Ed25519 atas BYTE `theme.json` yang diunduh; entri katalog tidak bertanda tangan dan tidak dipercaya; (b) kunci publik tertanam di kode; (c) url unduhan https saja, host sama dengan katalog, redirect dibatasi; (d) batas body ditegakkan saat streaming; (e) URL katalog konstanta/config, bukan pengaturan admin.

## UI (Filament / Blade)
`Site/Theme`: Radio dari `ListPortalThemes` (nama/deskripsi dari manifest, di-escape; tema rusak nonaktif dengan alasan). T2: page `Site/ThemeCatalog` (`getModule()` Konten). Teks di `lang/id/pondok.php`; ikuti `docs/design-guide.md` (§0, status, state kosong/gagal) dan panggil `/anti-ui-slop`.
- **Daftar route bertema tunggal:** `portal.*` kecuali sitemap/robots, plus `psb.info`; satu pemeriksaan di layout. Halaman 404, `verify-*`, `psb.register|status` (Livewire), dan `portal-wali.*` tetap chrome inti.

## Permission & cakupan
`manage:themes` baru (pola `PAGE_PERMISSIONS`), hanya `super-admin`, dipakai T2. Pilih tema tetap `view:school-profile`. Tanpa scope `unit_user`. Modul Konten; `ModuleComplianceTest` lulus. T2: `ext-sodium` ke `composer.json` + lock (disetujui user).

## Kriteria penerimaan
- **AC-1 (T1)** Validator: tiga tema resmi valid. Ditolak (data provider): tanpa/`schema_version` tak didukung, kunci tak dikenal (termasuk `assets`), komponen tak dikenal atau di slot salah, nilai gaya di luar enum (`url(`, `expression(`, `@import`, `;`, `}`, `javascript:`, `<script>`, hex bebas), kedalaman > 6, > 150 node, > 64 KB (tanpa memanggil `json_decode`), depth JSON > 8, UTF-8 rusak, JSON rusak, slug ≠ nama direktori.
- **AC-2 (T1)** Mesin gaya: CSS hanya berisi deklarasi dari peta server (tidak ada byte nilai JSON); panggilan kedua dalam request yang sama memakai memoisasi dan tidak menambah query; ubah isi tema = CSS baru; varian responsif menjadi media query. Semua `tone` x skala default dan skala F070 ekstrem memenuhi kontras 4,5:1 teks/latar.
- **AC-3 (T1)** Header/footer: tema `klasik` merender header dan footer dari JSON dengan data inti yang sama (nama, logo, menu, tombol, widget); jumlah query sama dengan layout inti; URL `javascript:` atau HTML di teks sekolah tetap dibuang/disanitasi; tema tanpa `header`/`footer` memakai partial inti. Tema tidak membawa teks portal (semua teks dari data sekolah/`lang/id`).
- **AC-4 (T1)** Migrasi beranda: untuk tujuh tipe blok, keluaran `default` dan `centered` sama dengan golden pra-migrasi (dinormalisasi hash Vite/spasi); `portal.home_blocks` lama tanpa migrasi data tampil sama; blok kosong tetap dibuang; `tone: auto` selang-seling sama dengan `$band()` lama; tipe yang tidak dipetakan memakai komponen bawaan; teks blok lewat `SanitizeBlockText`.
- **AC-5 (T1)** Aksesibilitas, tiap tema resmi dengan beranda lengkap: tautan lompat fokus pertama menuju satu `<main id="konten">`, tepat satu `h1` (`brand` bukan h1), tanpa loncatan level heading, dua `nav` punya `aria-label` berbeda, semua `a`/`button` komponen punya kelas fokus terlihat yang tak bisa dihapus properti gaya.
- **AC-6 (T1)** Pemilihan: `ListPortalThemes` = tiga tema resmi (tema resmi menang atas terpasang bila slug sama; slug terpasang yang bentrok ditolak); `UpdatePortalTheme` menyimpan slug valid, menolak tak dikenal/tidak valid; `TEMPLATES` tak ada lagi; nilai lama `centered`/`default` tetap bekerja; token F070 tetap berlaku; Radio memakai daftar ini; tanpa `view:school-profile` 403.
- **AC-7 (T1)** Respons: untuk tiap tema, `portal.home`, berita, `psb.info` memiliki `Cache-Control` public + `max-age` + ETag utuh (304 pada permintaan ulang), tanpa `csrf-token`, aset Livewire, atau `Set-Cookie`. Dengan `klasik` aktif, `psb.register`, `portal-wali.login`, halaman 404, dan `verify-*` tetap chrome inti.
- **AC-8 (T1)** Fallback: tema aktif hilang, JSON rusak, `schema_version` 99, melebihi batas, atau `min_core` > inti: portal 200 dengan chrome inti tanpa exception; `Log::warning` sekali per slug per proses (beberapa render = satu log); halaman `Site/Theme` tetap tampil, tema rusak nonaktif dengan alasan.
- **AC-9 (T1)** Tanpa kode dari tema: isi `resources/themes` hanya `theme.json`; `component` tak dikenal tak pernah jadi path view; test arsitektur menegaskan renderer tak memakai nilai JSON sebagai nama view/kelas mentah; `ModuleComplianceTest` lulus; teks baru lewat `lang/id`.
- **AC-10 (T2)** Katalog (client di-fake): `super-admin` melihat status belum/terpasang/pembaruan/tidak cocok (`min_core`, `schema_version`); `admin-sekolah` 403; katalog timeout/500/rusak/> 256 KB: halaman 200 dan daftar terpasang tetap tampil. Url unduhan non-https, host berbeda dari katalog, atau redirect melebihi batas ditolak; body melebihi batas dihentikan saat streaming (bukan dibaca penuh).
- **AC-11 (T2)** Unduh via antrean `database`: status `queued`->`running`->`done`; klik ganda tidak membuat job kedua dan penguncian atomik (`Cache::lock` atau update status bersyarat); gagal/timeout menulis `failed` (juga lewat `failed()`); `queued|running` > 5 menit tampil `failed` dan bisa diulang.
- **AC-12 (T2)** Verifikasi, semua menolak tanpa mengubah tema terpasang/`theme.*`: tanda tangan Ed25519 salah/terpotong/kunci lain atas byte `theme.json` (entri katalog tanpa tanda tangan ditolak), sha256 salah, slug/versi ≠ katalog, versi lebih rendah dari terpasang (replay saat pasang awal diterima), ukuran melebihi batas, tema gagal `ValidatePortalTheme`. JSON dibaca sekali ke memori; yang diverifikasi = yang ditulis.
- **AC-13 (T2)** Pasang atomik (staging lalu rename; kegagalan menyisakan tema lama utuh), update menyimpan satu versi di `_previous`, rollback mengembalikan satu langkah lalu mengosongkan `_previous`, hapus tema memakai fallback inti dan tidak menyentuh `theme.*` maupun konten.
- **AC-14 (T2)** `ext-sodium` ada di `REQUIRED_EXTENSIONS`; check `theme_catalog` di `CheckSystemHealth` (`ok`/`warning`/`skipped` bila Konten mati) tampil di `pondok:doctor`; `pondok:theme-sign` (kunci uji) menghasilkan entri katalog yang lolos AC-12 dan gagal jelas bila kunci/tema tak valid.

## Rencana test
- T1: `tests/Feature/Konten/PortalThemeSchemaTest.php` (AC-1, AC-9), `PortalThemeStylesTest.php` (AC-2), `PortalThemeRenderTest.php` (AC-3, AC-4, AC-5, AC-7, AC-8), `ListPortalThemesTest.php` + `ThemePageTest` (AC-6).
- **Tugas red-tester (RED), tes lama yang rusak:** perbarui `GetPortalThemeTest` (`blockView`/`TEMPLATES`), `UpdatePortalThemeTest`, `PortalTemplateTest`, dan label `lang` `pondok.school_profile.theme.templates` agar mengikuti rancangan baru; golden AC-4 direkam sebelum refaktor.
- T2: `ThemeCatalogPageTest.php` (AC-10, AC-11), `InstallPortalThemeTest.php` (AC-12, AC-13; satu test tiap: tanda tangan atas byte, url unduhan, batas streaming), `SystemHealthThemeTest.php` + `ThemeSignCommandTest.php` (AC-14).

## Urutan implementasi
1. **Paket A, skema + mesin gaya (hijau: PortalThemeSchemaTest, PortalThemeStylesTest):** `ThemeComponents`, `ValidatePortalTheme`, `MigrateThemeSchema` (kerangka N-1), `BuildThemeStyles`, tiga `theme.json`.
2. **Paket B, renderer + migrasi (hijau: PortalThemeRenderTest, test portal/PSB/beranda yang ada):** `ListPortalThemes`, `ResolveActiveTheme`, partial header/footer, view komponen berstatik + varian, `GetPortalHomeData`, layout, fallback + log.
3. **Paket C, pemilihan + modul (hijau: ListPortalThemesTest, ThemePageTest, ModuleComplianceTest):** hapus `TEMPLATES`/`blockView`, `UpdatePortalTheme`, `Site/Theme`, `lang/id`, `docs/rilis.md` (panduan skema, kebijakan versi), cek 360/1024/desktop.
4. **T2, setelah issue ada:** D data + verifikasi + pasang; E katalog + job + page; F doctor + sodium.

## Risiko & pertanyaan terbuka
- **Kompatibilitas skema selamanya:** dimitigasi set kunci tertutup, enum gaya, N dan N-1, `min_core`.
- **Injeksi CSS/URL/teks:** ditutup secara konstruksi (tak ada string bebas dari JSON ke CSS, URL, nama view); tetap diuji adversarial (AC-1, AC-3).
- **Tampilan basi:** setelah ganti tema, portal basi ≤ 5 menit (cache publik), dijelaskan di UI.
- **Pertanyaan 1 (user), mesin gaya:** mesin berbasis CSS (`BuildThemeStyles`) adalah keputusan user dan dipertahankan. Alternatif lebih sederhana: peta enum -> kelas Tailwind literal yang dipindai saat build (tanpa `BuildThemeStyles`, tanpa CSS runtime, tanpa kelas `t-<hash>`); konsekuensi: gaya hanya dari kombinasi yang ada di peta, responsif `{base,md,lg}` menjadi varian kelas. Belum diputuskan.
- **Pertanyaan 2 (user), `assets`:** user menetapkan "JSON + aset gambar/font", tetapi tak ada komponen v1 yang memakainya. Opsi A (default rencana, rekomendasi): keluarkan `assets` dari v1 dan T2, tambahkan bersama komponen pertama yang memakainya (naik `schema_version` atau tambahan kompatibel via `min_core`). Opsi B: tetap, dengan satu varian komponen v1 yang memakai gambar (punya pemakai/test); bila B dipilih, tambahkan `assets?` (`{file, sha256, size}`) ke skema, whitelist `png|jpg|webp|woff2` (nama datar, gambar ≤ 1 MB, font ≤ 400 KB, ≤ 20 berkas, total ≤ 4 MB, magic byte), aset di disk `public` `themes/<slug>/<versi>/`, dan ke AC-12/AC-13.
- **Terbuka (T2):** URL katalog resmi dan pemegang kunci privat; jangan diisi sebelum diputuskan. Replay/rollback ke tanda tangan lama pada pasang awal diterima di v1; rotasi kunci di luar v1.
- **Konfirmasi:** (1) pemecahan dua issue/PR; (2) tema ketiga `klasik` (header tengah, footer ringkas).
- **Beda dengan issue #133 (body perlu diperbarui):** kriteria 3 dipenuhi secara konstruksi (enum `tone`); `theme.template` berisi slug; kriteria 8-9 menjadi T2; `assets` ditunda (Pertanyaan 2).

## Review #1 (plan-reviewer, CHANGES_REQUESTED) dan keputusan
| # | Temuan | Keputusan |
|---|---|---|
| 1 | Cache validasi/CSS = query tambahan | Cache dihapus, memoisasi per request, log per proses; alternatif Tailwind jadi Pertanyaan 1 |
| 2 | Migrasi beranda kurang eksplisit | Bagian "Migrasi beranda" (a-d), kunci = tipe blok, golden ternormalisasi |
| 3 | `assets` tak punya pemakai | Opsi A default (dikeluarkan), B di Pertanyaan 2 |
| 4 | Keamanan T2 | Tanda tangan atas byte, kunci tertanam, https/host/redirect, streaming, URL konstanta; AC-10/12 |
| 5 | Kontrak T1->T2 | Daftar ganda, resmi menang, slug bentrok ditolak, slug = nama direktori |
| 6 | Daftar route tema | Dirinci di UI, AC-7 mencakup 404 dan `verify-*` |
| 7 | Validator | Ukuran sebelum decode, depth, UTF-8 (AC-1) |
| 8 | Enum/varian berlebih | Dibatasi pada yang dipakai tema resmi; AC-5 ditambah `brand` bukan h1 dan `aria-label` beda |
| 9 | Tes lama rusak | Tugas red-tester di Rencana test |
| 10 | "Tanpa teks tampil" | Diganti "tidak membawa teks portal" (AC-3) |
| 11 | Penguncian T2 | AC-11 atomik; AC-12 tolak versi lebih rendah, terima replay pasang awal |

## Riwayat revisi
- 2 Nov 2026: ditulis ulang total mengikuti arah baru issue #133 (tema JSON); DRAFT.
- 2 Nov 2026: revisi 1 menyikapi Review #1 (11 temuan); status APPROVED (setelah revisi 1).
