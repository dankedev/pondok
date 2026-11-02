# F102 konten-katalog-tema — Tema portal berbasis JSON: renderer dan tema bawaan (Tahap 1)

Issue: #133
Status rencana: APPROVED (revisi 3 menyikapi Review #2, tanpa review ulang)
PR ditujukan ke `dev` (cabang `feat/f102-katalog-tema`), memakai `Refs #133`, bukan `Closes`.
Menggantikan rencana lama (tema paket Blade + zip); versi lama ada di riwayat git.

## Ringkasan & tujuan
Tema = JSON. Inti punya renderer; tema tidak membawa Blade/PHP/JS. Gaya = enum yang dipetakan server ke kelas Tailwind literal (dipindai saat build). Sekolah memilih tema dan menyetel token F070; data konten tetap milik sekolah. Cakupan: header, footer, bagian beranda. Tanpa jaringan.

## Pemecahan
Tahap 2 (katalog, unduh, pasang) = F103 / #134, `depends_on: F102`, direncanakan sendiri. Catatan keamanannya disimpan untuk F103. Rencana ini hanya Tahap 1.

## Ruang lingkup
- Termasuk: skema v1 + validator, renderer, peta gaya, tiga tema resmi `default`/`centered`/`klasik`, `ListPortalThemes`, header/footer inti jadi partial, pemilihan tema, fallback.
- Tidak termasuk: semua hal Tahap 2 (F103), builder, penyuntingan struktur oleh sekolah, JS/HTML/CSS bebas, nilai warna/font/url bebas, aset gambar/font tema (ditunda, keputusan user), tema berbayar/pihak ketiga, marketplace, tema untuk halaman isi/bersesi.

## Skema v1 (`theme.json`), set kunci tertutup
`schema_version` (int), `slug`, `name`, `description`, `version` (semver), `min_core`, `header?`, `footer?`, `home.sections.<tipe_blok>?`. Tanpa `assets`.
- **Node:** `{variant?, style?, children?}` plus `component` untuk header/footer. `children` hanya pada `container`. Pada `home.sections`, kunci = tipe blok dan `component` diturunkan dari pemetaan di bawah.
- **Pustaka 12 komponen:** `container`, `brand`, `nav_menu` (header); `footer_widgets`, `copyright` (footer); beranda `hero`, `text_image`, `feature_grid`, `quote`, `cta`, `video`, `feed_list`. Slot sah dan `variant` per komponen di satu peta tetap (`ThemeComponents`), dibatasi pada yang dipakai tiga tema resmi.
- **Gaya = enum:** `tone` (`auto|light|muted|brand|dark`), `padding` (`sm|md|lg`), `gap` (`sm|md|lg`), `align` (`left|center`), `width` (`narrow|wide|full`), `radius` (`none|md|lg`), `shadow` (`none|sm`), `border` (`none|top|bottom`), `columns` (`2|3|4`). Responsif `{base, md, lg}` hanya untuk `columns` dan `padding` (dipakai `klasik`); properti lain hanya `base`. Hanya nilai yang dipakai tema resmi. Tanpa warna, url, font-family, string bebas.
- **Batas:** berkas ≤ 64 KB (dicek sebelum `json_decode`), depth ≤ 8, UTF-8 rusak ditolak, kedalaman pohon ≤ 6, node ≤ 150, `slug` `^[a-z0-9-]{2,40}$` dan sama dengan nama direktori, kunci tak dikenal menolak.
- **Versi skema:** naik hanya untuk perubahan merusak; inti mendukung N dan N-1 lewat `MigrateThemeSchema` (dalam memori); tambahan kompatibel cukup menaikkan `min_core`. Di luar dukungan = fallback.
- **Pemetaan blok -> komponen:** `hero`, `text_image`, `features`->`feature_grid`, `quote`, `cta`, `video`, `feed`->`feed_list`. Data blok (`ParseContentBlocks`, `NormalizeContentBlocks`, `GetPortalHomeData`, `SanitizeBlockText`, `portal.home_blocks`) tidak berubah dan tidak dimigrasi. Tipe tak dipetakan tema memakai komponen bawaan inti.

## Peta gaya (pengganti mesin CSS)
`App\Actions\Konten\ThemeStyleMap`: array konstanta enum -> string kelas Tailwind literal, mis. `padding.lg` -> `'py-12'`, responsif -> kelas `md:`/`lg:` tertulis utuh. Hanya lookup; kunci tak dikenal melempar `InvalidArgumentException` (validator mencegahnya lebih dulu). Tidak ada CSS runtime, `BuildThemeStyles`, `t-<hash>`, atau `<style data-theme-layout>`. Tailwind 4 mendeteksi sumber otomatis, jadi tanpa `@source` baru. Cek kelas ada di CSS hasil build = langkah `npm run build` saat rilis, bukan test (`public/build` di-.gitignore, tanpa Node di test).
- **Pasangan `tone` (hanya yang dijamin atau tak bergantung tema):** light = `bg-white`/`bg-gray-50` + `text-gray-900`; muted = `bg-gray-100` + `text-gray-900`; brand = `bg-brand text-on-brand` (dijamin `BuildColorScale::brandColors`); dark = `bg-primary-950 text-white` (dijamin `contrastFailure`: putih vs primary-950). Teks sekunder, tautan, dan fokus di dalam tone memakai kelas tetap di peta (dicek sama). Tanpa kelas warna lain di peta.
- **Awalan tak beririsan:** tiap properti peta punya awalan utilitas sendiri (padding `py-`, gap `gap-`, columns `grid-cols-`, dst.); view komponen tidak memuat kelas berawalan itu pada mode `klasik` agar tak bentrok dan fokus tak bisa ditimpa.

## Migrasi beranda
- (a) `default` dan `centered` tanpa `style` di beranda: varian = view statis `portal.theme.<komponen>` dengan kelas Tailwind lama (`default`->`split`, `centered`->`centered`); HTML identik. Hanya `klasik` memakai peta gaya.
- (b) `tone: auto` = selang-seling latar dihitung renderer (menggantikan closure `$band()`), berdasarkan urutan blok yang tampil.
- (c) Renderer mengoper `headingId`, `band`, `psbOpen` (dari `GetPortalHomeData`) sebagai variabel eksplisit ke view komponen.
- (d) Golden direkam sebelum refaktor, dinormalisasi dari hash Vite dan spasi; pakai ulang pola `PortalTemplateTest`.

## Model data
Tanpa migration. `theme.template` (Setting, F072) berisi slug tema (`default`/`centered` lama tetap valid). Tema resmi di `resources/themes/<slug>/theme.json`.

## Action class (`app/Actions/Konten/`)
- `ValidatePortalTheme` (pohon ternormalisasi atau galat), `ListPortalThemes` (hanya membaca `resources/themes`; antarmuka satu daftar sehingga F103 bisa menambah sumber tanpa membongkar), `ResolveActiveTheme` (tema aktif valid atau fallback inti), `ThemeComponents`, `ThemeStyleMap`, `MigrateThemeSchema` (kerangka N-1).
- Tanpa Cache: validasi dimemoisasi per request (statis); nilai `theme.template` sudah dimuat F070 (0 query tambahan). `Log::warning` sekali per slug per proses lewat penanda statis.
- Renderer: komponen Blade rekursif `<x-portal.theme-node>`; `component` hanya dipetakan lewat peta tetap ke view inti (tidak pernah `@include` dari nilai JSON). `layouts/public` menghitung `$school`, `$navigation`, `$footer` sekali; header/footer inti dipindah ke `portal.partials.header|footer` (jadi fallback).
- `GetPortalTheme` tetap untuk token F070; `TEMPLATES` dan `blockView` dihapus. `UpdatePortalTheme` menerima slug valid dari `ListPortalThemes`.

## UI
`Site/Theme`: Radio dari `ListPortalThemes` (nama/deskripsi dari manifest, di-escape; tema rusak nonaktif dengan alasan; catatan portal basi ≤ 5 menit). Teks di `lang/id/pondok.php`; ikuti `docs/design-guide.md` dan panggil `/anti-ui-slop`. Route bertema tunggal: `portal.*` kecuali sitemap/robots, plus `psb.info`; satu pemeriksaan di layout. 404, `verify-*`, `psb.register|status`, `portal-wali.*` tetap chrome inti.

## Permission & cakupan
Tanpa permission baru. Pilih tema tetap `view:school-profile`. `manage:themes` dipindah ke F103: di F102 tak ada aksi yang membutuhkannya (YAGNI), baru dipakai saat pasang/hapus tema. Penyimpangan dari issue #133 kriteria 9 (sebut `manage:themes`); body issue perlu diperbarui. Tanpa scope `unit_user`; `ModuleComplianceTest` lulus (Konten).

## Kriteria penerimaan
- **AC-1** Validator: tiga tema resmi valid. Ditolak (data provider): tanpa/`schema_version` tak didukung, kunci tak dikenal (termasuk `assets`), komponen tak dikenal atau di slot salah, nilai gaya di luar enum (`url(`, `expression(`, `@import`, `;`, `}`, `javascript:`, `<script>`, hex bebas), kedalaman > 6, > 150 node, > 64 KB (tanpa `json_decode`), depth > 8, UTF-8 rusak, JSON rusak, slug ≠ nama direktori.
- **AC-2** Peta gaya, cek statis PHP: (i) tiap kelas peta cocok pola token Tailwind yang diizinkan; (ii) tiap kelas muncul sebagai literal utuh di `ThemeStyleMap.php`; (iii) berkas tanpa interpolasi/penyambungan kelas (`"md:".`, `py-{$x}`, `sprintf`). Tiap enum sah menghasilkan kelas peta, responsif hanya `columns`/`padding`. Kontras: urai `bg-*`/`text-*` tiap `tone`, pasangan harus masuk daftar terjamin di atas, dan dihitung >= 4,5:1 dengan skala default dan F070 ekstrem lewat `BuildColorScale`. Awalan properti tak beririsan, dan view komponen tak memuat kelas berawalan itu saat `klasik`.
- **AC-3** Header/footer: `klasik` merender header dan footer dari JSON dengan data inti yang sama (nama, logo, menu, tombol, widget); jumlah query sama dengan layout inti; URL `javascript:`/HTML di teks sekolah tetap dibuang/disanitasi; tema tanpa `header`/`footer` memakai partial inti; tema tak membawa teks portal.
- **AC-4** Migrasi beranda: tujuh tipe blok, keluaran `default` dan `centered` sama dengan golden pra-migrasi; `portal.home_blocks` lama tampil sama tanpa migrasi data; blok kosong dibuang; `tone: auto` selang-seling sama dengan `$band()` lama; tipe tak dipetakan memakai komponen bawaan; teks lewat `SanitizeBlockText`.
- **AC-5** Aksesibilitas, tiap tema resmi: tautan lompat fokus pertama ke satu `<main id="konten">`, tepat satu `h1` (`brand` bukan h1), tanpa loncatan level heading, dua `nav` ber-`aria-label` berbeda, semua `a`/`button` komponen punya kelas fokus terlihat yang tak bisa dihapus properti gaya.
- **AC-6** Pemilihan: `ListPortalThemes` = tiga tema resmi; `UpdatePortalTheme` menyimpan slug valid dan menolak tak dikenal/tidak valid; `TEMPLATES` tak ada lagi; nilai lama `centered`/`default` tetap bekerja; token F070 tetap berlaku; Radio memakai daftar ini; tanpa `view:school-profile` 403.
- **AC-7** Respons: tiap tema, `portal.home`, berita, `psb.info` ber-`Cache-Control` public + `max-age` + ETag utuh (304 saat ulang), tanpa `csrf-token`, aset Livewire, atau `Set-Cookie`. Dengan `klasik` aktif, `psb.register`, `portal-wali.login`, 404, `verify-*` tetap chrome inti.
- **AC-8** Fallback: tema aktif hilang, JSON rusak, `schema_version` 99, melebihi batas, atau `min_core` > inti: portal 200 dengan chrome inti tanpa exception; `Log::warning` sekali per slug per proses; `Site/Theme` tetap tampil, tema rusak nonaktif dengan alasan.
- **AC-9** Tanpa kode dari tema: isi `resources/themes` hanya `theme.json`; `component` tak dikenal tak pernah jadi path view; test arsitektur: `ThemeStyleMap` mengembalikan string dari array konstanta dan melempar untuk kunci tak dikenal; view/komponen tak memasukkan `$node['style']` ke `class` kecuali lewat `ThemeStyleMap`, dan tak memakai nilai JSON sebagai nama view; `ModuleComplianceTest` lulus; teks baru lewat `lang/id`.

## Rencana test
`tests/Feature/Konten/PortalThemeSchemaTest.php` (AC-1, AC-9), `PortalThemeStyleMapTest.php` (AC-2), `PortalThemeRenderTest.php` (AC-3, AC-4, AC-5, AC-7, AC-8), `ListPortalThemesTest.php` + `ThemePageTest` (AC-6).
**Tugas red-tester (RED), tes lama yang rusak:** perbarui `GetPortalThemeTest` (`blockView`/`TEMPLATES`), `UpdatePortalThemeTest`, `PortalTemplateTest`, dan label `lang` `pondok.school_profile.theme.templates`; golden AC-4 direkam sebelum refaktor.

## Urutan implementasi
1. **Paket A, skema + peta gaya (hijau: PortalThemeSchemaTest, PortalThemeStyleMapTest):** `ThemeComponents`, `ValidatePortalTheme`, `MigrateThemeSchema`, `ThemeStyleMap`, tiga `theme.json`.
2. **Paket B, renderer + migrasi (hijau: PortalThemeRenderTest, test portal/PSB/beranda yang ada):** `ListPortalThemes`, `ResolveActiveTheme`, partial header/footer, view komponen statis + varian, `GetPortalHomeData`, layout, fallback + log.
3. **Paket C, pemilihan + modul (hijau: ListPortalThemesTest, ThemePageTest, ModuleComplianceTest):** hapus `TEMPLATES`/`blockView`, `UpdatePortalTheme`, `Site/Theme`, `lang/id`, `docs/rilis.md` (panduan skema, kebijakan versi), cek 360/1024/desktop.

## Risiko & pertanyaan terbuka
- **Kompatibilitas skema selamanya:** dimitigasi set kunci tertutup, enum gaya, N dan N-1, `min_core`.
- **Injeksi:** ditutup secara konstruksi (hanya lookup peta, tak ada string bebas ke kelas/URL/nama view); tetap diuji adversarial (AC-1, AC-2, AC-3).
- **Kelas Tailwind tak terpindai:** cek statis AC-2 menjaga literal utuh; keberadaan di CSS dicek lewat `npm run build` saat rilis.
- **Kombinasi gaya terbatas:** hanya yang ada di peta; menambah nilai = ubah peta + rilis inti (disengaja).
- **Tampilan basi:** setelah ganti tema, portal basi ≤ 5 menit (cache publik), dijelaskan di UI.
- **Terbuka (F103/#134):** URL katalog resmi dan pemegang kunci privat; tidak dibahas di rencana ini.

## Review #1 (plan-reviewer, CHANGES_REQUESTED) dan keputusan
Ringkasan: 11 temuan; yang berkaitan T2 (keamanan unduh, kontrak daftar bersumber ganda/slug bentrok, penguncian job, replay versi) dipindah ke F103 dan dicatat di berkas catatan F103. Sisanya diselesaikan di rencana ini: cache dihapus (memoisasi per request), migrasi beranda dieksplisitkan (a-d), validator (ukuran sebelum decode, depth, UTF-8), daftar route tema, enum dibatasi pada tema resmi, tes lama rusak jadi tugas red-tester, klaim "tanpa teks tampil" diganti "tidak membawa teks portal".
Keputusan user (2 Nov 2026): (1) mesin gaya = peta enum -> kelas Tailwind literal, `BuildThemeStyles` dibuang; (2) `assets` ditunda dari v1; (3) dipecah F102/F103; (4) tema `klasik` disetujui; (5) `manage:themes` pindah ke F103.

## Review #2 (ThemeStyleMap, ringan): 2 major + 4 minor, diselesaikan
Major: test CSS hasil build diganti tiga cek statis PHP; pasangan latar/teks `tone` dibatasi pada yang dijamin. Minor: `@source` dihapus, responsif dibatasi `columns`/`padding`, test awalan tak beririsan, test arsitektur AC-9 konkret.

## Riwayat revisi
- 2 Nov 2026: ditulis ulang total mengikuti arah tema JSON; DRAFT.
- 2 Nov 2026: revisi 1 menyikapi Review #1; APPROVED (setelah revisi 1).
- 2 Nov 2026: revisi 2 mengikuti keputusan user: rencana dipecah (hanya Tahap 1, AC-10..14 pindah ke F103/#134), mesin gaya diganti peta kelas Tailwind literal (`ThemeStyleMap`), `assets` ditunda, `manage:themes` pindah ke F103; AC dirapikan jadi 9.
- 2 Nov 2026: revisi 3 menyikapi Review #2 (lihat di atas).
