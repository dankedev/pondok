# F102 konten-katalog-tema — Tema portal berbasis JSON: renderer dan tema bawaan (Tahap 1)

Issue: #133
Status rencana: MENUNGGU TINJAUAN (revisi 4, arah baru user 2 Nov 2026: susunan bebas ala Elementor)
PR ditujukan ke `dev` (cabang `feat/f102-katalog-tema`), memakai `Refs #133`, bukan `Closes`.

## Ringkasan & tujuan
Tema = JSON. Inti punya renderer; tema tidak membawa Blade/PHP/JS. Gaya = enum yang dipetakan server ke kelas Tailwind literal. **Susunan halaman tidak dibatasi sistem**: komponen boleh di posisi mana pun, berulang, dan bersarang; hero tidak wajib di atas. Susunan disimpan di `theme.json` (Tahap ini). Editor visual admin sekolah dan susunan per situs di DB = issue lanjutan memakai skema yang sama (catatan, tidak dikerjakan, YAGNI). Tahap 2 (katalog/unduh) = F103 / #134.

## Ruang lingkup
- Termasuk: skema v1 berpohon `home.layout`, validator, renderer, peta gaya, varian, empat tema resmi (`default`, `centered`, `majalah`, `mushaf`; `klasik` dibuang), `ListPortalThemes`, header/footer dengan model node yang sama, pemilihan tema, fallback.
- Tidak termasuk: editor visual, susunan per situs di DB, aset gambar/font tema, JS/HTML/CSS/URL/warna/font bebas, tema pihak ketiga, tema untuk halaman isi/bersesi, pemilihan blok ke-n (`nth`), semua hal F103.

## Skema v1 (`theme.json`), set kunci tertutup
`schema_version`, `slug`, `name`, `description`, `version`, `min_core`, `header?`, `footer?`, `home.layout?` (daftar node, urutan bebas). `home.sections` dihapus (kunci tak dikenal, ditolak). Belum dirilis dan belum ada tema di luar repo: `schema_version` tetap 1, tanpa migrasi N-1 (`MigrateThemeSchema` tetap kerangka kosong).
- **Node:** `{component, variant?, style?, children?}`. Header dan footer = satu node akar bermodel sama.
- **Komponen (15):** tata letak `container` (susun vertikal; di header berderet), `columns` (anak = sel; 2-4 anak), `divider`; chrome `brand`, `nav_menu`, `footer_widgets`, `copyright`; isi beranda `hero`, `text_image`, `feature_grid`, `quote`, `cta`, `video`, `feed_list`; `blocks` (lihat Pengikatan). Slot sah per komponen di `ThemeComponents::SLOTS` (`header`: container, columns, divider, brand, nav_menu; `footer`: container, columns, divider, footer_widgets, copyright; `home`: container, columns, divider, tujuh isi, blocks). Dipilih tanpa `section`/`stack`/`spacer`: `container` + `padding` + `gap` sudah mencakupnya.
- **`children`** hanya pada `container` dan `columns`; bersarang bebas dalam batas kedalaman. Isi beranda boleh berulang di level atas maupun di dalam container/kolom, tanpa aturan "setelah X harus Y".
- **Varian** (lookup ke markup/kelas literal; bawaan = yang pertama): `hero` center|split|image_bg; `feed_list` cards|list|magazine; `quote` plain|framed; `cta` band|card; `feature_grid` cards|numbered; `divider` line|ornament (emas hanya garis/hiasan, `bg-accent-*`); `blocks` split|centered (hanya untuk preset lama). `text_image`, `video`, kolom, container tanpa varian.
- **Gaya = enum** (peta `ThemeStyleMap`): tone, padding, gap, align, width, radius, shadow, border, columns (`1|2|3|4`; `1` baru, untuk 360px). Responsif `{base, md, lg}` hanya `columns`/`padding`. `tone: auto` (selang-seling) hanya sah pada node level atas `home.layout`; di dalam container/kolom = ditolak. `blocks` tanpa `style` dan `variant` wajib.
- **Batas:** berkas ≤ 64 KB (sebelum decode), UTF-8, kedalaman ≤ 6 (`home.layout` level atas = 1), node ≤ 150 total (header+footer+layout), `slug` `^[a-z0-9-]{2,40}$` sama dengan nama direktori, kunci tak dikenal menolak, `blocks` hanya level atas dan maksimal satu.

## Pengikatan data
Data blok (`ParseContentBlocks`, `GetPortalHomeData`, `SanitizeBlockText`, `portal.home_blocks`) tidak berubah. Node isi mengikat blok **aktif pertama bertipe sama** (hero<-`hero`, text_image, feature_grid<-`features`, quote, cta, video, feed_list<-`feed`). Penempatan berulang menampilkan konten yang sama. Tanpa blok bertipe itu (kosong, dibuang, modul konten mati) node dan seluruh pembungkusnya tidak dirender; kolom yang kosong semua tetap hanya merender sel yang ada. Node `blocks` = semua blok aktif sesuai urutan dan status dari admin (perilaku beranda lama); dipakai `default` (`split`) dan `centered` (`centered`) memakai view legacy `themes/<preset>/blocks/*`, sehingga HTML identik golden. Admin dengan beberapa blok sejenis hanya melihat semuanya di tema `blocks`; pilihan blok ke-n = catatan lanjutan.
- **Judul h1:** hero pertama yang tampil memakai `h1`, hero berikutnya `h2`. Tanpa hero tampil: preset `blocks` tetap pita nama lembaga seperti sekarang; tema `layout` bebas memakai `h1` sr-only nama lembaga di awal `<main>` (tak memaksa posisi apa pun).
- **Tone auto:** selang-seling `bg-gray-50`/`bg-white` dihitung renderer hanya atas node level atas ber-`tone: auto` yang tampil (urutan DOM). Di `blocks`, latar selang-seling lama berjalan sendiri (indeks lokal). Node bersarang tak memakai `portal-gutter`, tone, maupun padding bawaan bagian; hanya `style`-nya sendiri.

## Peta gaya
`ThemeStyleMap`: konstanta enum -> kelas Tailwind literal; kunci tak dikenal melempar `InvalidArgumentException`; tanpa CSS runtime. Pasangan `tone` hanya yang dijamin kontrasnya (light/muted/brand/dark, seperti rencana lama). Tiap properti punya awalan utilitas sendiri. Markup varian ditulis literal di view `portal/theme/styled/*` (tanpa interpolasi kelas). Mengecek kelas ada di CSS = `npm run build` saat rilis.

## Model data & Action class
Tanpa migration. `theme.template` berisi slug tema; nilai `klasik` lama (hanya data demo) jatuh ke fallback (tema hilang). Tema resmi di `resources/themes/<slug>/theme.json` via `config('pondok.themes_path')`.
Action (`app/Actions/Konten/`): `ValidatePortalTheme` (satu `assertNode` generik per slot `header|footer|home`, menggantikan `assertHome`), `ThemeComponents`, `ThemeStyleMap`, `ListPortalThemes`, `ResolveActiveTheme`, `MigrateThemeSchema`, dan `ResolveHomeSections` diganti nama jadi `ResolveHomeLayout`: berjalan di pohon `home.layout`, mengikat blok, memberi `headingId` unik per penempatan, level judul, dan `band`. Renderer `<x-portal.theme-node>` rekursif; `component` hanya kunci `@switch` ke include berliteral (tidak pernah nama view dari JSON). `layouts/public` hitung `$school`, `$navigation`, `$footer` sekali.

## Tema resmi
- `default`, `centered`: `home.layout: [{component: blocks, variant: split|centered}]`, header/footer inti; HTML identik.
- `majalah` (editorial): kutipan `plain` di paling atas, `hero split` di tengah, `columns` (`1` di mobile, `2` di md) berisi `feed_list list` dan container bersarang (`feature_grid numbered` + `cta card`), `feed_list magazine` di bawah, lalu `feed_list cards` lagi (blok sama dua kali). Header putih bergaris, footer `dark`.
- `mushaf` (tenang, rata tengah): `hero center`, `divider ornament`, `quote framed` (bingkai garis emas halus), `text_image`, `feature_grid cards`, `divider ornament`, `quote framed` lagi, `cta band`, `feed_list cards`. Header/footer rata tengah memakai `container` + `columns`.
- Keduanya: warna hanya token (design-guide §2, emas hanya garis/latar/hiasan, tidak pernah `text-accent-*`), 360px tanpa scroll horizontal, JSON tanpa teks portal selain `name`/`description`, konten contoh mengikuti manhaj CLAUDE.md.

## UI & permission
`Site/Theme` Radio dari `ListPortalThemes` (tema rusak nonaktif beserta alasan, catatan portal basi ≤ 5 menit); teks di `lang/id/pondok.php`; ikuti `docs/design-guide.md` dan panggil `/anti-ui-slop` hanya bila perlu. Route bertema: `portal.*` kecuali sitemap/robots, plus `psb.info`. Tanpa permission baru (`view:school-profile` tetap; `manage:themes` di F103). Tanpa scope `unit_user`; `ModuleComplianceTest` lulus.

## Kriteria penerimaan
- **AC-1** Validator: tema resmi valid. Ditolak: tanpa/`schema_version` tak didukung, kunci tak dikenal (termasuk `assets` dan `home.sections`), `layout` bukan daftar, komponen tak dikenal atau di slot salah, `children` pada non-container/columns, `columns` dengan anak < 2 atau > 4, `blocks` bersarang/ganda/bergaya/bervarian di luar `split|centered`, varian tak sah, `tone: auto` pada node bersarang atau di header/footer, nilai gaya di luar enum (`url(`, `;`, `javascript:`, hex bebas, dll.), kedalaman > 6, > 150 node, > 64 KB (tanpa `json_decode`), UTF-8/JSON rusak, slug ≠ direktori.
- **AC-2** Susunan bebas: tema uji dengan `quote` di paling atas, `hero` di tengah, `feed_list` dua kali, `columns` bersarang di `container` dirender dalam urutan DOM persis JSON; sah di validator tanpa aturan urutan; node terdalam (kedalaman 6) tampil.
- **AC-3** Pengikatan: penempatan berulang menampilkan konten blok pertama sama; tanpa blok bertipe itu node dan pembungkus tak dirender (tanpa elemen kosong); modul konten mati tidak meng-query konten; `portal.home_blocks` lama tetap, teks lewat `SanitizeBlockText`; `blocks` memuat semua blok aktif sesuai urutan admin.
- **AC-4** Golden: `default` dan `centered` sama dengan golden pra-migrasi (`PortalThemeGoldenTest` tak direkam ulang); latar selang-seling `blocks` sama dengan `$band()` lama.
- **AC-5** `tone: auto`: selang-seling level atas menurut urutan DOM yang tampil, node kosong tak menggeser; bersarang tak memakai gutter/tone/padding bagian.
- **AC-6** Varian: tiap varian (data provider) menghasilkan penanda markup berbeda; tanpa `variant` = varian pertama; `image_bg` tanpa gambar tidak error (latar tone); markup varian tanpa interpolasi kelas (cek statis).
- **AC-7** Peta gaya (cek statis PHP): kelas cocok pola token, literal utuh di `ThemeStyleMap.php`, tanpa interpolasi; `columns` memuat `1`; responsif hanya `columns`/`padding`; kontras tiap pasangan `tone` >= 4,5:1 pada skala default dan F070 ekstrem; awalan tak beririsan; `ThemeStyleMap` melempar untuk kunci tak dikenal.
- **AC-8** Header/footer: tema `majalah` dan `mushaf` merender dari JSON (termasuk `columns`/`divider`) dengan data inti sama, jumlah query sama dengan layout inti, `javascript:`/HTML di teks sekolah tetap dibuang; tanpa `header`/`footer` = partial inti.
- **AC-9** Aksesibilitas tiap tema resmi (dengan hero, tanpa hero, dan hero berulang): tepat satu `h1` (hero kedua `h2`, tanpa hero `h1` sr-only atau pita preset), tanpa loncatan level, satu `<main id="konten">` dengan tautan lompat fokus pertama, dua `nav` ber-`aria-label` berbeda, `a`/`button` berkelas fokus terlihat yang tak bisa ditimpa gaya; tema bersarang tanpa `overflow-x` pada 360px (cek kelas `columns` base `1`).
- **AC-10** Pemilihan: `ListPortalThemes` = empat tema resmi (tanpa `klasik`); `UpdatePortalThemeTest` menyimpan slug valid dan menolak tak dikenal; token F070 tetap; tanpa `view:school-profile` 403; nilai `klasik` lama = fallback inti.
- **AC-11** Respons dan fallback: tiap tema, `portal.home`, berita, `psb.info` ber-`Cache-Control` public + ETag (304 saat ulang), tanpa `csrf-token`, aset Livewire, `Set-Cookie`; `psb.register`, `portal-wali.login`, 404, `verify-*` tetap chrome inti; tema hilang/rusak/`schema_version` 99/melebihi batas/`min_core` > inti memberi 200 chrome inti, `Log::warning` sekali per slug per proses, tema rusak nonaktif di `Site/Theme`.
- **AC-12** Tanpa kode dari tema: isi `resources/themes` hanya `theme.json`; `component`/`variant` tak pernah jadi nama view atau kelas; test arsitektur: `$node['style']` ke `class` hanya lewat `ThemeStyleMap`; `ModuleComplianceTest` lulus; teks baru lewat `lang/id`.

## Rencana test
`PortalThemeSchemaTest` (AC-1), `PortalThemeStyleMapTest` (AC-7, AC-12), `PortalThemeRenderTest` (AC-2, 3, 5, 6, 8, 9, 11), `PortalThemeGoldenTest` (AC-4, tidak direkam ulang), `ListPortalThemesTest` + `UpdatePortalThemeTest` + `ThemePageTest` (AC-10).
**Berkas test lama yang harus disesuaikan red-tester** (menyentuh `home.sections` atau `klasik`): `tests/Pest.php` (helper `themeOfficialManifest`, komentar F102), `Actions/Konten/PortalThemeSchemaTest`, `Http/Portal/PortalThemeAdversarialTest` (banyak kasus `home.sections`/`klasik`), `Http/Portal/PortalThemeRenderTest`, `Actions/Konten/ListPortalThemesTest`, `Actions/Konten/UpdatePortalThemeTest`, `Filament/Pages/ThemePageTest`, `PortalThemeStyleMapTest` (`columns` 1); cek `PortalTemplateTest`/`PortalThemeGoldenTest`. Kunci lang `theme_schema.tone_auto_home_only` diubah.

## Urutan implementasi
1. **Paket A, skema + peta gaya (hijau: PortalThemeSchemaTest, PortalThemeStyleMapTest):** `ThemeComponents` (slot, varian), `ValidatePortalTheme` (`assertNode` generik, `home.layout`), `ThemeStyleMap` (`columns` 1), `theme.json` empat tema.
2. **Paket B, renderer (hijau: PortalThemeRenderTest, PortalThemeGoldenTest, test portal/PSB yang ada):** `ResolveHomeLayout`, `node.blade` (+ `columns`/`divider`/`blocks`), view varian styled, `home.blade`, header/footer dengan model sama, fallback + log.
3. **Paket C, pemilihan + dokumen (hijau: ListPortalThemesTest, UpdatePortalThemeTest, ThemePageTest, ModuleComplianceTest):** hapus `klasik`, `lang/id`, `docs/rilis.md` (daftar berkas dan panduan `home.layout`), `docs/design-guide.md` §309-310, cek 360/1024/desktop.

## Risiko & pertanyaan terbuka
- Isi bersarang memakai wrapper bagian yang sama dengan level atas: green-coder menjaga mode "bersarang" tanpa gutter/padding ganda; golden tak boleh berubah.
- Sekolah dengan beberapa blok sejenis hanya melihat blok pertama di tema `layout` bebas; `nth` dan editor visual = issue lanjutan (usul, menunggu persetujuan user).
- Perubahan body issue #133 diperlukan (susunan bebas, `klasik` diganti `majalah`/`mushaf`, `manage:themes` ke F103).
- Kompatibilitas skema selamanya dimitigasi set kunci tertutup, enum, `min_core`; injeksi ditutup secara konstruksi (lookup peta saja).

## Riwayat revisi
- 2 Nov 2026: rencana tema JSON; revisi 1-3 menyikapi review #1/#2 dan keputusan user (peta Tailwind literal, `assets` ditunda, pecah F102/F103, `manage:themes` ke F103); APPROVED. Detail ada di riwayat git.
- 2 Nov 2026, revisi 4 (arah baru user: seperti Elementor): `home.sections` (gaya per tipe, urutan admin) diganti `home.layout` (pohon node urutan bebas, boleh berulang dan bersarang); komponen tata letak baru `columns`, `divider` (+ `container` dipakai di beranda), `blocks` untuk preset lama (golden identik); header/footer lewat `assertNode` yang sama; varian ditambahkan; `columns` + nilai `1`; `tone: auto` hanya level atas; hero berulang = h2; `klasik` diganti `majalah` dan `mushaf`; AC disusun ulang jadi 12. Kode yang disesuaikan: `ValidatePortalTheme`, `ThemeComponents`, `ThemeStyleMap`, `ResolveHomeSections` (jadi `ResolveHomeLayout`), `node.blade.php`, `home.blade.php`, view `portal/theme/styled/*`, `resources/themes/*`, `lang/id`, `docs/rilis.md`, `docs/design-guide.md`. Test: lihat Rencana test.
