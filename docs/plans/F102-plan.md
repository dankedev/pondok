# F102 konten-katalog-tema — Tema portal berbasis JSON: renderer dan tema bawaan (Tahap 1)

Issue: #133
Status rencana: APPROVED (revisi 5, setelah Review #1 susunan bebas: 4 major terjawab)
PR ditujukan ke `dev` (cabang `feat/f102-katalog-tema`), memakai `Refs #133`, bukan `Closes`.

## Ringkasan & tujuan
Tema = JSON. Inti punya renderer; tema tidak membawa Blade/PHP/JS. Gaya = enum yang dipetakan server ke kelas Tailwind literal. **Susunan halaman tidak dibatasi sistem**: komponen boleh di posisi mana pun, berulang, dan bersarang; hero tidak wajib di atas. Susunan disimpan di `theme.json` (Tahap ini). Editor visual admin sekolah dan susunan per situs di DB = issue lanjutan memakai skema yang sama (catatan, YAGNI). Tahap 2 (katalog/unduh) = F103 / #134.

## Ruang lingkup
- Termasuk: skema v1 berpohon `home.layout`, validator, renderer, peta gaya, varian, empat tema resmi (`default`, `centered`, `majalah`, `mushaf`; `klasik` dibuang), `ListPortalThemes`, header/footer dengan model node yang sama, pemilihan tema, fallback.
- Tidak termasuk: editor visual, susunan per situs di DB, aset gambar/font tema, JS/HTML/CSS/URL/warna/font bebas, tema pihak ketiga, tema untuk halaman isi/bersesi, pemilihan blok ke-n (`nth`), semua hal F103.

## Skema v1 (`theme.json`), set kunci tertutup
`schema_version`, `slug`, `name`, `description`, `version`, `min_core`, `header?`, `footer?`, `home.layout?` (daftar node, urutan bebas). `home.sections` dihapus (kunci tak dikenal, ditolak). Belum dirilis dan belum ada tema di luar repo: `schema_version` tetap 1, tanpa migrasi N-1 (`MigrateThemeSchema` tetap kerangka kosong).
- **Node:** `{component, variant?, style?, children?}`. Header dan footer = satu node akar bermodel sama.
- **Komponen (15):** tata letak `container` (susun vertikal; di header berderet), `columns` (anak = sel; 2-4 anak), `divider`; chrome `brand`, `nav_menu`, `footer_widgets`, `copyright`; isi beranda `hero`, `text_image`, `feature_grid`, `quote`, `cta`, `video`, `feed_list`; `blocks`. Slot sah di `ThemeComponents::SLOTS` (`header`: container, columns, divider, brand, nav_menu; `footer`: container, columns, divider, footer_widgets, copyright; `home`: container, columns, divider, tujuh isi, blocks). Tanpa `section`/`stack`/`spacer`: `container` + `padding` + `gap` sudah mencakupnya.
- **`children`** hanya pada `container` dan `columns`; bersarang bebas dalam batas kedalaman. Isi beranda boleh berulang di level atas maupun di dalam container/kolom, tanpa aturan "setelah X harus Y".
- **`blocks`** (preset lama) wajib **satu-satunya** node di `home.layout` (daftar tepat satu elemen), tanpa `style`, varian `default|centered`. Tidak ada aturan campuran blocks dengan node lain. Tanpa `home.layout` = `[{blocks, default}]`.
- **Varian** (lookup ke markup/kelas literal; bawaan = yang pertama): `hero` center|split; `feed_list` cards|list|magazine; `quote` plain|framed; `cta` band|card; `feature_grid` cards|numbered; `blocks` default|centered. `divider` satu bentuk bawaan (hiasan `ornament`, garis/latar `bg-accent-*`, tidak pernah `text-accent-*`); `text_image`, `video`, `columns`, `container` tanpa varian.
- **Gaya = enum** (peta `ThemeStyleMap`): tone, padding, gap, align, width, radius, shadow, border, columns. Responsif `{base, md, lg}` hanya `columns`/`padding`. `style.columns` (`1|2|3|4`; `1` baru, untuk 360px) hanya sah pada `columns`; nilai > jumlah anak ditolak validator, dan renderer memakai `min(nilai, jumlah sel yang tampil)`; tanpa `style.columns` = jumlah sel yang tampil pada md. `tone: auto` (selang-seling) hanya sah pada node level atas `home.layout` (bukan blocks).
- **Batas:** berkas ≤ 64 KB (sebelum decode), UTF-8, kedalaman ≤ 6 (`home.layout` level atas = 1), node ≤ 150 total (header+footer+layout), `slug` `^[a-z0-9-]{2,40}$` sama dengan nama direktori, kunci tak dikenal menolak.

## Pengikatan data
Data blok (`ParseContentBlocks`, `GetPortalHomeData`, `SanitizeBlockText`, `portal.home_blocks`) tidak berubah. Node isi mengikat blok **aktif pertama bertipe sama** (hero<-`hero`, feature_grid<-`features`, feed_list<-`feed`, dst.). Penempatan berulang menampilkan konten yang sama. `blocks` = semua blok aktif sesuai urutan/status admin lewat view legacy `themes/<preset>/blocks/*` (HTML identik golden).
- **Node kosong (satu aturan):** sebuah node tampil bila ada >=1 anak isi yang tampil. Node isi (hero/text_image/feature_grid/quote/cta/video/feed_list) tampil bila blok aktifnya ada; `blocks`, `container`, `columns` dihitung menurut anaknya. Node yang tidak tampil tidak merender elemen apa pun (termasuk pembungkus). `divider` tampil hanya bila bersebelahan dengan konten yang tampil, dan tidak ikut hitungan `tone: auto`. Sel `columns` yang kosong tidak dirender.
- **Judul:** hero pertama yang tampil memakai `h1`, selanjutnya `h2`. Bila ada node isi bertajuk (text_image/feature_grid/cta/video/feed_list) tampil sebelum hero pertama, hero tetap `h2` dan `h1` sr-only nama lembaga ditaruh di awal `<main>`. Tanpa hero tampil: preset `blocks` = pita nama lembaga seperti sekarang; layout bebas = `h1` sr-only di awal `<main>`. `headingId` unik per penempatan (tanpa `id` ganda saat hero/feed_list berulang).
- **Tone auto:** selang-seling `bg-gray-50`/`bg-white` dihitung hanya atas node level atas ber-`tone: auto` yang tampil (urutan DOM). Di `blocks`, latar selang-seling lama berjalan sendiri. Node bersarang tak memakai `portal-gutter`, tone, maupun padding bawaan bagian; hanya `style`-nya sendiri.

## Peta gaya
`ThemeStyleMap`: konstanta enum -> kelas Tailwind literal; kunci tak dikenal melempar `InvalidArgumentException`; tanpa CSS runtime. Pasangan `tone` hanya yang dijamin kontrasnya (light/muted/brand/dark). Tiap properti punya awalan utilitas sendiri. Markup varian ditulis literal di view `portal/theme/styled/*` (tanpa interpolasi kelas). Mengecek kelas ada di CSS = `npm run build` saat rilis.

## Model data & Action class
Tanpa migration. `theme.template` berisi slug tema; nilai `klasik` lama (data demo) jatuh ke fallback. Tema resmi di `resources/themes/<slug>/theme.json` via `config('pondok.themes_path')`.
Action (`app/Actions/Konten/`): `ValidatePortalTheme` (satu `assertNode` generik per slot, menggantikan `assertHome`), `ThemeComponents`, `ThemeStyleMap`, `ListPortalThemes`, `ResolveActiveTheme`, `MigrateThemeSchema`, dan `ResolveHomeSections` diganti nama `ResolveHomeLayout`: berjalan di pohon `home.layout`, mengikat blok, memberi `headingId`, level judul, dan `band`. Renderer `<x-portal.theme-node>` rekursif; `component` hanya kunci `@switch` ke include berliteral (tak pernah nama view dari JSON). `layouts/public` hitung `$school`, `$navigation`, `$footer` sekali.

## Tema resmi
- `default`, `centered`: `home.layout: [{component: blocks, variant: default|centered}]`, header/footer inti; HTML identik.
- `majalah` (editorial): `quote plain` di paling atas, `hero split` di tengah, `columns` (`style.columns` base 1, md 2) berisi `feed_list list` dan container bersarang (`feature_grid numbered` + `cta card`), `feed_list magazine`, lalu `feed_list cards` (blok sama dua kali). Header putih bergaris, footer `dark`.
- `mushaf` (tenang, rata tengah): `hero center`, `divider`, `quote framed`, `text_image`, `feature_grid cards`, `divider`, `quote framed` lagi, `cta band`, `feed_list cards`. Header = `columns` + `nav_menu` + `brand`, footer rata tengah memakai `container` + `columns`.
- Keduanya: warna hanya token (design-guide §2), JSON tanpa teks portal selain `name`/`description`, konten contoh mengikuti manhaj CLAUDE.md.

## UI & permission
`Site/Theme` Radio dari `ListPortalThemes` (tema rusak nonaktif beserta alasan, catatan portal basi <= 5 menit); teks di `lang/id/pondok.php`; ikuti `docs/design-guide.md`. Route bertema: `portal.*` kecuali sitemap/robots, plus `psb.info`. Tanpa permission baru (`view:school-profile` tetap; `manage:themes` di F103). Tanpa scope `unit_user`; `ModuleComplianceTest` lulus.

## Kriteria penerimaan
- **AC-1** Struktur: tema resmi valid. Ditolak: tanpa/`schema_version` tak didukung, kunci tak dikenal (termasuk `assets`, `home.sections`), `layout` bukan daftar, komponen tak dikenal atau di slot salah, `children` pada non-container/columns, `columns` anak < 2 atau > 4 atau `style.columns` > jumlah anak, `blocks` bersarang/bersama node lain/ganda/bergaya/bervarian di luar `default|centered`, varian tak sah (termasuk `hero image_bg`, `divider` bervarian), `tone: auto` pada node bersarang atau di header/footer, slug != direktori. Tanpa `home.layout` = `[{blocks, default}]`.
- **AC-2** Batas: kedalaman > 6, > 150 node, > 64 KB (tanpa `json_decode`), UTF-8/JSON rusak ditolak; node di kedalaman 6 sah dan tampil.
- **AC-3** Nilai gaya di luar enum ditolak (`url(`, `;`, `javascript:`, hex bebas, responsif pada properti selain `columns`/`padding`, dll.).
- **AC-4** Susunan bebas: tema uji dengan `quote` di paling atas, `hero` di tengah, `feed_list` dua kali, `columns` bersarang di `container` dirender dalam urutan DOM persis JSON; sah tanpa aturan urutan.
- **AC-5** Pengikatan dan node kosong: penempatan berulang menampilkan konten blok pertama yang sama; tanpa blok bertipe itu node dan seluruh pembungkus tak dirender (tanpa elemen kosong); `columns` dengan sel kosong memakai jumlah kolom sel yang tampil; `divider` tanpa konten bersebelahan tak tampil; modul konten mati tidak meng-query konten; teks lewat `SanitizeBlockText`; `blocks` memuat semua blok aktif sesuai urutan admin.
- **AC-6** Golden: `default` dan `centered` sama dengan golden pra-migrasi (`PortalThemeGoldenTest` tak direkam ulang); latar selang-seling `blocks` sama dengan `$band()` lama.
- **AC-7** `tone: auto`: selang-seling level atas menurut urutan DOM yang tampil, node kosong dan `divider` tak menggeser; cek statis: node bersarang tidak memuat `portal-gutter`, tone, atau padding bagian.
- **AC-8** Varian: tiap varian (data provider) menghasilkan penanda markup berbeda; tanpa `variant` = varian pertama; markup varian tanpa interpolasi kelas (cek statis).
- **AC-9** Peta gaya dan tanpa kode dari tema (cek statis): kelas cocok pola token, literal utuh di `ThemeStyleMap.php`, tanpa interpolasi; `columns` memuat `1`; responsif hanya `columns`/`padding`; kontras tiap pasangan `tone` >= 4,5:1 (skala default dan F070 ekstrem); awalan tak beririsan; kunci tak dikenal melempar; isi `resources/themes` hanya `theme.json`; `component`/`variant` tak pernah jadi nama view atau kelas; `$node['style']` ke `class` hanya lewat `ThemeStyleMap`.
- **AC-10** Header/footer: `majalah` dan `mushaf` merender dari JSON (termasuk `columns`/`divider`) dengan data inti sama dan jumlah query sama dengan layout inti; `javascript:`/HTML di teks sekolah dibuang; tanpa `header`/`footer` = partial inti.
- **AC-11** Heading (tiap tema resmi dan tema uji: dengan hero, tanpa hero, hero berulang, node bertajuk sebelum hero): tepat satu `h1` (hero kedua `h2`; hero setelah node bertajuk `h2` + `h1` sr-only di awal `<main>`; tanpa hero `h1` sr-only atau pita preset), tanpa loncatan level, tanpa `id` ganda.
- **AC-12** Landmark dan fokus: satu `<main id="konten">` dengan tautan lompat fokus pertama, dua `nav` ber-`aria-label` berbeda, `a`/`button` berkelas fokus terlihat yang tak bisa ditimpa gaya.
- **AC-13** 360px: tema bersarang tanpa `overflow-x` (kelas `columns` base `1`); header `mushaf` (`columns` + `nav_menu`) tetap punya toggle menu mobile.
- **AC-14** Pemilihan: `ListPortalThemes` = empat tema resmi (tanpa `klasik`); `UpdatePortalThemeTest` menyimpan slug valid dan menolak tak dikenal; token F070 tetap; tanpa `view:school-profile` 403; nilai `klasik` lama = fallback inti.
- **AC-15** Respons dan fallback: tiap tema, `portal.home`, berita, `psb.info` ber-`Cache-Control` public + ETag (304 saat ulang), tanpa `csrf-token`, aset Livewire, `Set-Cookie`; `psb.register`, `portal-wali.login`, 404, `verify-*` tetap chrome inti; tema hilang/rusak/`schema_version` 99/melebihi batas/`min_core` > inti memberi 200 chrome inti, `Log::warning` sekali per slug per proses, tema rusak nonaktif di `Site/Theme`.

## Rencana test
`PortalThemeSchemaTest` (AC-1, 2, 3), `PortalThemeStyleMapTest` (AC-9), `PortalThemeRenderTest` (AC-4, 5, 7, 8, 10, 11, 12, 13, 15), `PortalThemeGoldenTest` (AC-6, tidak direkam ulang), `ListPortalThemesTest` + `UpdatePortalThemeTest` + `ThemePageTest` (AC-14).
**Berkas test lama yang harus disesuaikan red-tester** (menyentuh `home.sections` atau `klasik`): `tests/Pest.php` (helper `themeOfficialManifest`), `Actions/Konten/PortalThemeSchemaTest`, `Http/Portal/PortalThemeAdversarialTest`, `Http/Portal/PortalThemeRenderTest`, `Actions/Konten/ListPortalThemesTest`, `Actions/Konten/UpdatePortalThemeTest`, `Filament/Pages/ThemePageTest`, `PortalThemeStyleMapTest` (`columns` 1); cek `PortalTemplateTest`/`PortalThemeGoldenTest`. Kunci lang `theme_schema.tone_auto_home_only` diubah.

## Urutan implementasi
1. **Paket A, skema + peta gaya (hijau: PortalThemeSchemaTest, PortalThemeStyleMapTest):** `ThemeComponents`, `ValidatePortalTheme` (`assertNode`, `home.layout`), `ThemeStyleMap` (`columns` 1), `theme.json` empat tema.
2. **Paket B, renderer (hijau: PortalThemeRenderTest, PortalThemeGoldenTest, test portal/PSB yang ada):** `ResolveHomeLayout`, `node.blade` (+ `columns`/`divider`/`blocks`), view varian styled, `home.blade`, header/footer dengan model sama, fallback + log.
3. **Paket C, pemilihan + dokumen (hijau: ListPortalThemesTest, UpdatePortalThemeTest, ThemePageTest, ModuleComplianceTest):** hapus `klasik`, `lang/id`, `docs/rilis.md` (panduan `home.layout`), `docs/design-guide.md` §309-310, cek 360/1024/desktop.

## Risiko & pertanyaan terbuka
- Isi bersarang memakai wrapper bagian yang sama dengan level atas: green-coder menjaga mode "bersarang" tanpa gutter/padding ganda; golden tak boleh berubah.
- Sekolah dengan beberapa blok sejenis hanya melihat blok pertama di tema layout bebas; `nth` dan editor visual = issue lanjutan (usul, menunggu persetujuan user).
- Body issue #133 perlu diperbarui (susunan bebas, `klasik` diganti `majalah`/`mushaf`, `manage:themes` ke F103).
- Catatan implementasi (minor Review #1): kompatibilitas skema dimitigasi set kunci tertutup, enum, `min_core`; injeksi ditutup lewat lookup peta saja.

## Review #1 (plan-reviewer): CHANGES_REQUESTED, 0 blocker, 4 major, 5 minor
Major: (1) `blocks` bercampur dengan node lain; (2) aturan node kosong/`columns`/`divider` ambigu; (3) varian `hero image_bg` dan `divider line` tak dipakai tema resmi; (4) AC-1 dan AC-9 terlalu gemuk. Minor: aturan h1 bila node bertajuk mendahului hero, cek statis `portal-gutter` pada node bersarang, `id` ganda saat hero/feed_list berulang. Semuanya diterapkan di revisi 5.

## Riwayat revisi
- 2 Nov 2026: rencana tema JSON; revisi 1-3 menyikapi review awal dan keputusan user (peta Tailwind literal, `assets` ditunda, pecah F102/F103, `manage:themes` ke F103); APPROVED. Detail di riwayat git.
- 2 Nov 2026, revisi 4 (arah baru user: seperti Elementor): `home.sections` diganti `home.layout` (pohon node urutan bebas), komponen `columns`/`divider`/`blocks`, `klasik` diganti `majalah` dan `mushaf`.
- 2 Nov 2026, revisi 5 (Review #1): `blocks` satu-satunya node (varian `default|centered`); satu aturan node kosong + `style.columns` = min(nilai, sel tampil); `hero image_bg` dan `divider line` dihapus; aturan h1 dengan node bertajuk dan `headingId` unik; AC dipecah dan digabung menjadi 15.
