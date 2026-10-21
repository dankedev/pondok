# F072 konten-preset-template — Pilihan preset template portal

Issue: #25
Status rencana: APPROVED

## Ringkasan & tujuan
Admin memilih satu preset template beranda portal di Situs > Tampilan. Preset adalah set view di repo (`resources/views/themes/<nama>/blocks/*.blade.php`) yang merender **blok yang sama dari isian yang sama** (`portal.home_blocks`); navigasi, header, dan footer tetap dari layout publik bersama. Berganti template hanya menulis `theme.template`, jadi isian tidak hilang. Sumber: `docs/audits/ui-ux-portal-konten.md` §5.8. Bergantung F070 (done).

## Ruang lingkup
- Termasuk: setting `theme.template`; preset `default` (7 blok sekarang dipindah apa adanya) dan satu preset baru `centered` ("Terpusat"); fallback per blok ke preset default; Radio template di halaman Tampilan; pembaruan design guide §6.
- Tidak termasuk (eksplisit): editor/unggah tema, pratinjau, preset untuk halaman lain (berita, halaman, PSB, 404), preset header/footer/navigasi, motif dekorasi, preset ketiga, nilai warna/font bawaan per preset (template tidak menulis key `theme.*` lain), migrasi isian blok.

## Model data
Tanpa migration. Key baru `theme.template` (JSON `settings`): kunci preset atau null. **Bawaan `default` disimpan null** (pola F070). Ditambahkan ke `GetPortalTheme::KEYS`, otomatis ikut `GetSchoolProfile::KEYS` (cache yang sama, tanpa query tambahan). `portal.home_blocks` tidak disentuh.

## Action class
Tidak ada Action baru (KISS; perluasan Action F070).
1. `app/Actions/Konten/GetPortalTheme`
   - `KEYS` + `'theme.template'`; `DEFAULT_TEMPLATE = 'default'`; `TEMPLATES = ['default' => [], 'centered' => []]` (peta kunci server; label/deskripsi di `lang/id`, bukan di konstanta).
   - `template(): string` — `option(Setting::get('theme.template'), TEMPLATES) ?? DEFAULT_TEMPLATE`. Nilai rusak (array, `'Default'`, `'../portal/home'`, `"centered\n"`, kunci tak dikenal) → `default`, tanpa exception.
   - `handle()` menambah `template` (hasil `template()`) ke array keluaran; `css`/`font_aliases` tidak berubah. Template tidak memengaruhi `css`.
   - `static blockView(string $template, string $type): string` — `"themes.{$template}.blocks.{$type}"` bila `View::exists`, selain itu `"themes.default.blocks.{$type}"`. Dipanggil hanya dengan `$template` dari `template()` (kunci `TEMPLATES`) dan `$type` dari blok yang sudah lolos `NormalizeContentBlocks` (area `home`), jadi tidak ada string DB mentah di nama view.
2. `app/Actions/Konten/GetPortalHomeData` — setiap blok di `blocks` mendapat `view` = `GetPortalTheme::blockView($template, $block['type'])`, `$template` dibaca sekali per panggilan lewat `app(GetPortalTheme::class)->template()`.
3. `app/Actions/Konten/UpdatePortalTheme` — input `template?`; `$values['theme.template'] = $this->option($data['template'], 'template', GetPortalTheme::TEMPLATES, GetPortalTheme::DEFAULT_TEMPLATE)` bila key dikirim (null/''/`default` → null; tak dikenal/non-string → `theme_option_invalid` di `template`). Satu `saveProfileSettings` dan log `portal_theme_updated` yang sama (hanya bila berubah). Tidak menulis `portal.home_blocks`.

## UI (Filament / Blade)
- `git mv resources/views/portal/blocks/*.blade.php resources/views/themes/default/blocks/` — isi file **tidak diubah** (markup default identik).
- `resources/views/portal/home.blade.php`: `@include($block['view'], [...])` menggantikan `'portal.blocks.'.$block['type']`. Variabel bersama (`$band`, `$sectionLink`, `$sectionHeading`, `$hasHero`, `$school`) tetap didefinisikan di sini dan dipakai kedua preset; pita h1 tanpa-hero tetap bersama.
- `resources/views/themes/centered/blocks/` — override hanya blok yang tata letaknya beda (blok lain jatuh ke default):
  - `hero`: teks rata tengah (`text-center`, `mx-auto max-w-3xl`, tombol `justify-center`), media (gambar/album) lebar penuh di **bawah** teks; tanpa grid dua kolom. Satu `<h1>` dengan `id="{{ $headingId }}"`.
  - `features`: daftar bergaris (`divide-y`, tanpa kartu/bayangan), judul seksi rata tengah.
  - `text_image`: gambar di kiri pada `lg` (urutan dibalik dari default), teks di kanan.
  - `quote`: kutipan rata tengah dengan tanda kutip dekoratif `aria-hidden`.
  - Kontrak sama dengan default: satu `<section>` per blok (`aria-labelledby` hanya bila ada judul, seperti default), judul `<h2>` (hero `<h1>`), memakai `$band()`, `<x-ui.button>`/`$block['buttons']`, teks dari `$data` yang sama, alt gambar sama, tidak menampilkan field yang tidak ada di default.
- `app/Filament/Pages/Site/Theme.php`: `Radio::make('template')` di atas font, `->options(...)` dari `pondok.school_profile.theme.templates.<kunci>.label` dan `->descriptions(...)` dari `.description`, `columnSpanFull()`. `formData()` mengisi kunci tervalidasi (rusak → `default`); `persist()` meneruskan `template`.
- `lang/id/pondok.php` → `school_profile.theme`: `template` "Template beranda", `template_helper` "Mengubah tata letak blok beranda. Isi blok tidak berubah.", `templates.default` {label "Standar (bawaan)", description "Hero dua kolom dengan gambar di samping, keunggulan dalam kartu."}, `templates.centered` {label "Terpusat", description "Hero rata tengah dengan gambar lebar di bawahnya, keunggulan sebagai daftar bergaris."}. `section_description` diperbarui menyebut template.
- `docs/design-guide.md` §6 baris "Beranda (F045)": path `resources/views/themes/<preset>/blocks/{type}.blade.php`, preset `default` dan `centered`, aturan fallback ke default, kontrak heading/section/band sama. Rujukan: §0, §6, §13 (`/anti-ui-slop` untuk preset `centered`).

## Permission & cakupan
Tanpa permission baru: halaman Tampilan sudah `view:school-profile` (super-admin, admin-sekolah, admin-konten). Tanpa `unit_user`, tanpa saklar modul (inti portal).

## Kriteria penerimaan (bisa dites)
- AC-1 Bawaan: tanpa `theme.template` → `GetPortalTheme::template()` = `default`, `handle()['template']` = `default`, `css` tetap null; setiap blok `GetPortalHomeData` punya `view` = `themes.default.blocks.<type>`.
- AC-2 Simpan valid: `UpdatePortalTheme` dengan `['template' => 'centered']` → `theme.template` = `centered`, satu log `konten` `portal_theme_updated` berisi `changed`; simpan ulang sama → tanpa log baru. `['template' => 'default']` (atau `''`/null) di DB kosong → key tetap null, tanpa log; dari `centered` → kembali null.
- AC-3 Masukan ditolak: template `'../portal/home'`, `'Default'`, `'themes.centered'`, `'centered '`, `'tidak-ada'`, `['centered']`, `1` → `ValidationException` di `template` (`theme_option_invalid`); tidak ada setting tersimpan, termasuk key lain yang dikirim bersama.
- AC-4 Nilai DB rusak: `Setting::set('theme.template', ...)` dengan `'../portal/home'`, `"centered\n"`, `['centered']`, `'tidak-ada'` → `template()` = `default`; `GET /` 200 dan memakai markup default (hero dua kolom).
- AC-5 Resolusi view: `blockView('centered', 'hero')` = `themes.centered.blocks.hero`; `blockView('centered', 'cta')` = `themes.default.blocks.cta` (tidak dioverride); `View::exists` untuk `themes.default.blocks.<type>` benar untuk ketujuh tipe area `home`. `resources/views/portal/blocks/` tidak ada lagi.
- AC-6 Default tidak berubah: dengan isian berisi hero bergambar, `GET /` (template default) memuat hero `lg:grid-cols-2`; seluruh `tests/Feature/Http/Portal/HomeBlocksTest.php` lulus tanpa diubah.
- AC-7 Preset `centered` benar-benar beda: dengan isian yang sama, hero tidak memuat `lg:grid-cols-2` dan memuat `text-center`; seksi features tidak memuat `shadow-card` (kartu default) dan memuat `divide-y`.
- AC-8 Blok sama di semua preset: satu isian berisi ketujuh tipe (hero, features, feed, cta, quote, text_image, video; data uji `kontenAlbum`/`kontenVideo`), untuk setiap kunci `TEMPLATES`: `GET /` 200, satu `<h1>`, judul/teks tiap blok tampil dalam urutan tersimpan yang sama, jumlah `<section` dan nilai `aria-labelledby` sama, teks tombol dan URL tautan tombol sama, alt gambar sama.
- AC-9 Navigasi sama: untuk setiap preset, isi `<header>` dan `<footer>` HTML beranda identik (string sama) dengan preset default.
- AC-10 Isian tidak hilang: simpan isian blok, lalu `UpdatePortalTheme` `centered` → `default` → `centered`; nilai mentah `portal.home_blocks` identik dengan sebelum, dan teks blok tetap tampil di `GET /` setelah tiap pergantian.
- AC-11 Halaman lain tidak ikut: dengan `centered`, halaman berita (`portal.posts.index`) dan halaman PSB 200 dan markupnya sama dengan saat `default` (tidak memuat view `themes.centered`).
- AC-12 Form Filament: tanpa key → Radio `template` = `default`, opsi menampilkan "Standar (bawaan)" dan "Terpusat" beserta deskripsi; `fillForm(['template' => 'centered'])->call('save')` → `theme.template` = `centered`, notifikasi "Tampilan portal disimpan."; `template` di luar opsi → galat di `data.template`. Simpan form tidak mengubah `portal.home_blocks`.

## Rencana test
- `tests/Feature/Actions/Konten/UpdatePortalThemeTest.php` (tambah): AC-2, AC-3.
- `tests/Feature/Actions/Konten/GetPortalThemeTest.php` (tambah): AC-1, AC-4 (Action), AC-5.
- `tests/Feature/Http/Portal/PortalTemplateTest.php` (baru): AC-4 (HTML), AC-6, AC-7, AC-8, AC-9, AC-10, AC-11. Pakai helper global `blk`/`blkSet`/`blkHome` (`tests/Pest.php`).
- `tests/Feature/Filament/Pages/ThemePageTest.php` (tambah): AC-12.
- `HomeBlocksTest.php` tidak diubah (bukti AC-6).

## Urutan implementasi
Paket 1 — data + Action (hijau: `GetPortalThemeTest`, `UpdatePortalThemeTest`)
1. `GetPortalTheme`: `KEYS`, `TEMPLATES`, `DEFAULT_TEMPLATE`, `template()`, `blockView()`, `handle()['template']`.
2. `UpdatePortalTheme`: field `template` lewat `option()`.
Paket 2 — view preset (hijau: `PortalTemplateTest`, `HomeBlocksTest`, `PortalThemeTest`)
3. `git mv` 7 blok ke `themes/default/blocks/` + `home.blade.php` pakai `$block['view']` + `GetPortalHomeData` menambah `view` (satu commit agar tidak ada commit merah).
4. Preset `centered`: hero, features, text_image, quote (`/anti-ui-slop`, cek 360/1024/desktop). `npm run build` lalu cek kelas baru terbawa.
Paket 3 — admin + dokumen (hijau: `ThemePageTest`, `SiteNavigationGroupsTest`)
5. Radio `template` + `lang/id`. 6. design guide §6.

## Risiko & pertanyaan terbuka
- Nama/isi preset kedua (`centered`/"Terpusat") adalah keputusan desain dari orkestrator; issue tidak menyebut jumlah preset. Satu preset baru cukup untuk membuktikan kriteria (YAGNI).
- Kelas Tailwind baru di `themes/centered` hanya ada di CSS setelah `npm run build`; paket rilis wajib dibangun ulang (Tailwind v4 memindai `resources/views` otomatis; cek `@source` tidak perlu ditambah).
- `GetPortalHomeData` kini bergantung pada `GetPortalTheme::template()` (satu `Setting::get` dari cache yang sama) — biaya diabaikan.
- Preset `centered` harus menjaga kontrak aksesibilitas yang sama (satu h1, `aria-labelledby`, alt, fokus) karena AC-8 hanya menguji kesetaraan isi, bukan semua atribut.

## Riwayat revisi
- DRAFT 1 (21 Okt 2026): rencana awal.

## Review #1 (plan-reviewer, 21 Okt 2026): APPROVED
0 blocker, 0 major. Minor, diselesaikan saat implementasi:
1. Data uji AC-8/AC-9 menyertakan satu blok `visible=false`.
2. `View::exists` per blok per request dibiarkan (maks. 20 blok).
3. Pastikan build memuat kelas baru preset `centered`.
4. Rujukan `portal/blocks` lain hanya path penyimpanan gambar; cukup perbarui `docs/design-guide.md`.
5. Validasi `template` gagal sebelum `saveProfileSettings` (atomik, AC-3).
