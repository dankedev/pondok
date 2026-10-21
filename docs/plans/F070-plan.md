# F070 konten-tema-portal — Tema portal dari admin: warna, font, dan radius

Issue: #24
Status rencana: APPROVED (revisi 1 menyelesaikan temuan major review #1; siap implementasi)

## Ringkasan & tujuan
Admin mengatur warna utama, warna aksen, pasangan font, dan sudut kartu portal tanpa build ulang aset. Skala 50–950 dihitung di PHP, kontras divalidasi 4,5:1, token ditulis sebagai variabel CSS di `<style>` inline layout publik. Setting kosong = tampilan sekarang persis (tanpa `<style>` tema). Panel Filament tidak berubah. Sumber: `docs/audits/ui-ux-portal-konten.md` §5.8.

## Ruang lingkup
- Termasuk: 4 setting `theme.primary`, `theme.accent`, `theme.font`, `theme.radius`; Action simpan, penyusun skala, pembaca token; halaman Filament "Tampilan" di grup Situs; 2 font tambahan self-host; aturan judul `--font-heading`; pembaruan design guide §2/§3.
- Tidak termasuk (eksplisit): preset template dan motif (#25), blok beranda (#26), pratinjau langsung, dark mode, tema bayangan (`--shadow-*` tetap bernada navy), radius pill (`rounded-full` tetap), tema di `errors/layout.blade.php` (halaman 4xx/5xx global tanpa DB tetap bawaan; 404 memakai layout publik jadi ikut tema), tema panel Filament.

## Model data
Tanpa migration. Key `settings` (JSON, cache scoped `Setting`): `theme.primary` dan `theme.accent` = `#rrggbb` huruf kecil atau null; `theme.font` = kunci font atau null; `theme.radius` = kunci radius atau null. **Nilai yang sama dengan bawaan disimpan null** (warna kosong, font `jakarta`, radius `medium`), jadi simpan form tanpa ubahan tidak membuat log. Key bebas preset: #25 cukup menulis keempat key ini.

## Action class
1. `app/Actions/Konten/BuildColorScale` — `handle(string $hex, string $role): array<int,string>` (`$role` = `primary|accent`), plus `static contrast(string $a, string $b): float` (WCAG 2.x). Murni, tanpa DB. Dipakai oleh dua Action di bawah (alasan kelas terpisah: DRY nyata).
   - Konversi hex → OKLCH. Tangga lightness dan chroma tetap per peran, diambil dari skala `app.css` sekarang:
     - primary L: .961 .919 .835 .718 .588 .478 .394 .326 .264 .217 .183; C: .013 .026 .052 .087 .120 .135 .133 .123 .109 .100 .097
     - accent L: .981 .955 .918 .884 .852 .820 .723 .601 .481 .366 .256; C: .027 .065 .112 .151 .168 .168 .149 .124 .099 .075 .052
   - **Anchor:** warna masukan ditaruh persis di langkah dengan L tangga terdekat dengan L masukan (seri → langkah lebih gelap). Langkah lain: L tangga, hue masukan, C = C_masukan × C_tangga[s] / C_tangga[anchor], lalu chroma diturunkan (bisection) sampai masuk gamut sRGB. Masukan abu-abu (C < 0,0001) → hue 0. Keluaran `#rrggbb` huruf kecil.
   - Contoh hasil prototipe: `#000a3c`→950, `#061247`→900, `#1f2a5e`→700, `#15803d`→500 (primary); `#f8b800`→500 (accent).
   - **Aturan kontras (satu aturan, atas hasil skala; sama dengan AC-8)** — `static contrastFailure(array $primaryScale, array $accentScale): ?array{field: 'primary'|'accent', ratio: float}`, dipakai Action dan pembaca:
     - primary gagal bila salah satu < 4,5: `#ffffff` vs langkah 700/800/900/950 (teks primer di putih, teks putih di tombol/footer), atau langkah 100/200 vs 950 (teks footer).
     - accent gagal bila langkah 400/500 vs primary-950 efektif < 4,5 (teks `text-primary-950` di atas `bg-accent-400/500`). Primary-950 efektif = langkah 950 skala primary masukan, atau `#000a3c` bila primary kosong. Skala primary bawaan untuk aturan ini = hex `app.css` (tabel design guide §2).
     - `ratio` = pasangan terendah yang gagal. Karena tangga L tetap, masukan nyata praktis tidak pernah gagal (brute force 4.096 warna grid: terendah 8,7:1 primary, 8,2:1 accent); aturan ini jaring pengaman (mis. tangga diubah kelak, nilai preset #25) dan dites dengan skala buatan.
2. `app/Actions/Konten/UpdatePortalTheme` — pola `UpdatePortalFooter` (`SavesProfileSettings`, `fail()` → `pondok.konten.errors.*`). Input `{primary?, accent?, font?, radius?}`, aktor `User`.
   - Warna: null/'' → null; selain itu string di-trim harus cocok `/^#[0-9a-f]{6}\z/i` → huruf kecil; lainnya `theme_hex_invalid` di field itu.
   - Bangun skala lewat `BuildColorScale` (di-resolve dari container agar test bisa mengganti dengan skala buatan), lalu `contrastFailure()` → `theme_primary_contrast` / `theme_accent_contrast` di field yang gagal, `:ratio` dibulatkan ke bawah 1 desimal berkoma (`4,4:1`).
   - `font` ∈ kunci `GetPortalTheme::FONTS`, `radius` ∈ kunci `GetPortalTheme::RADII` (null/'' → bawaan); lainnya `theme_option_invalid`.
   - Semua galat dilempar sebelum simpan. Simpan lewat `saveProfileSettings([...4 key], 'portal_theme_updated', $actor)` (log `konten` hanya bila berubah).
3. `app/Actions/Konten/GetPortalTheme` — `handle(): array{css: ?string, font_aliases: ?list<string>}`. `KEYS` (4 key) ditambahkan ke `GetSchoolProfile::KEYS` (pola `GetPortalNavigation::SETTING_KEYS`) agar tanpa query tambahan.
   - **Validasi ulang nilai DB** tanpa `trim`: hex `/^#[0-9a-f]{6}\z/i`, `contrastFailure()` yang sama, enum. Nilai rusak/disisipkan/gagal → diabaikan per key (pakai bawaan), tanpa exception.
   - `css`: `:root{...}` hanya berisi `--color-primary-{50..950}` (bila primary valid), `--color-accent-*` (bila accent valid), deklarasi font dari peta server, `--radius-lg`/`--radius-xl` dari peta server. Semua bawaan → `null`. Tidak ada byte dari DB yang masuk ke CSS selain hex hasil `BuildColorScale`.
   - `FONTS` (OFL, self-host): `jakarta` (bawaan; Plus Jakarta Sans judul+isi; tanpa CSS), `lora` (Lora judul, Plus Jakarta Sans isi; `--font-heading: 'Lora', ui-serif, Georgia, serif`; aliases `['plus-jakarta-sans','lora','noto-naskh-arabic']`), `nunito` (Nunito judul+isi; `--font-sans: 'Nunito', ui-sans-serif, system-ui, sans-serif, ...emoji seperti app.css`; aliases `['nunito','noto-naskh-arabic']`).
   - **Alias font harus tersedia di build.** Font non-bawaan dipakai hanya bila semua alias-nya ada di `families` manifest font aktif: `public_path('build/fonts-manifest.json')`, atau `dirname(Vite::hotFile()).'/fonts-manifest.dev.json'` saat `Vite::isRunningHot()` (path sama dengan `Illuminate\Foundation\ViteFonts::manifest`). Berkas tidak ada, JSON rusak, atau alias kurang → font bawaan: tanpa CSS font dan `font_aliases = null`. Manifest dibaca sekali per request.
   - `font_aliases`: `null` untuk font bawaan (layout memanggil `@fonts(null)` = keluaran sama persis dengan `@fonts` sekarang), atau daftar alias di atas untuk font non-bawaan yang tersedia. Dengan begitu `@fonts` tidak pernah menerima alias yang tidak ada di manifest.
   - `RADII`: `small` (lg .25rem, xl .375rem), `medium` (bawaan .5rem/.75rem, tanpa CSS), `large` (lg .75rem, xl 1.25rem).

## UI (Filament / Blade)
- `app/Filament/Pages/Site/Theme.php` extends `SettingsFormPage`, slug `site/theme`, grup `pondok.navigation.situs`, `navigationSort = 4` (setelah Footer), ikon `Heroicon::OutlinedSwatch`, `pageKey()` = `theme`. Form satu `Section`, `columns(2)`: `ColorPicker` `primary` dan `accent` (hex, boleh kosong, helper aturan kontras + "Kosongkan untuk warna bawaan"), `Radio`/`Select` `font` (3 opsi, bawaan `jakarta`), `Radio` `radius` (3 opsi, bawaan `medium`). `formData()` mengisi dari settings mentah (null → kosong/bawaan); `persist()` memanggil `UpdatePortalTheme`.
- `resources/views/layouts/public.blade.php`: `$theme = app(GetPortalTheme::class)->handle()`; `@fonts` → `@fonts($theme['font_aliases'])`; setelah `@vite`: `@if ($theme['css']) <style data-portal-theme>{!! $theme['css'] !!}</style> @endif` (unlayered `:root` menang atas `@layer theme`; CSP `style-src 'unsafe-inline'` sudah mengizinkan).
- `resources/css/app.css`: `@layer base { h1, h2, h3 { font-family: var(--font-heading, var(--font-sans)); } }` (bawaan sama dengan sekarang; tidak menambah token `@theme`), plus komentar di atas `@theme`: blok ini tidak boleh diubah menjadi `@theme inline` dan utilitas portal tidak boleh memakai `theme()`, karena tema admin (F070) bergantung pada utilitas yang membaca `var()`.
- `vite.config.js`: `bunny('Lora', { weights: [600, 700], preload: false })`, `bunny('Nunito', { weights: [400, 600, 700], preload: false })`. Preload Plus Jakarta Sans tetap. Tambahan paket rilis ±150–250 KB (woff2+woff, subset latin).
- `lang/id/pondok.php`: `school_profile.pages.theme` "Tampilan", `saved.theme` "Tampilan portal disimpan.", `school_profile.theme.*` (label, helper, opsi font/radius), `konten.errors.theme_hex_invalid` ("Tulis warna dalam format #RRGGBB, misalnya #1A2C72."), `theme_primary_contrast` ("Skala warna utama menghasilkan teks dengan kontras hanya :ratio; minimal 4,5:1. Pilih warna lain."), `theme_accent_contrast` ("Teks di atas skala warna aksen hanya berkontras :ratio; minimal 4,5:1. Pilih warna lain."), `theme_option_invalid`. Helper warna: "Dipakai sebagai dasar skala; tombol dan teks memakai langkah gelap skala ini agar tetap terbaca. Kosongkan untuk warna bawaan."
- `docs/design-guide.md` §2 (skala tabel = bawaan; admin dapat mengganti lewat Tampilan, aturan emas tetap) dan §3 (daftar font tema). Rujukan: §0, §2, §3, §6, §13.

## Permission & cakupan
Tanpa permission baru: `view:school-profile` (super-admin, admin-sekolah, admin-konten) lewat `SettingsFormPage::canAccess()`. Tanpa `unit_user`, tanpa saklar modul (inti portal, sama dengan Footer).

## Kriteria penerimaan (bisa dites)
- AC-1 Bawaan: tanpa key `theme.*` → `GetPortalTheme` mengembalikan `css = null`, `font_aliases = null`; HTML beranda tidak memuat `data-portal-theme`; `@fonts` dipanggil dengan `null` (fake `Vite`, pola `PortalFontsTest`).
- AC-2 Simpan valid: `UpdatePortalTheme` dengan `#1F2A5E`, `#FFD452`, `nunito`, `large` → tersimpan `#1f2a5e`, `#ffd452`, `nunito`, `large`; satu log `konten` `portal_theme_updated` berisi `changed`. Simpan ulang data sama → tidak ada log baru.
- AC-3 Nilai bawaan → null: simpan `{primary: '', accent: null, font: 'jakarta', radius: 'medium'}` di DB kosong → keempat key tetap null/tidak ada, tanpa log.
- AC-4 Masukan rusak ditolak: warna `red`, `#fff`, `1f2a5e`, `#12345g`, `#000000;}`, array → `ValidationException` di field terkait (`theme_hex_invalid`); font `comic`/radius `huge` → `theme_option_invalid`; tidak ada setting tersimpan.
- AC-5 Aturan kontras atas skala (`BuildColorScale::contrastFailure`): skala primary buatan dengan langkah 900 = `#777777` (4,47:1 vs putih), lainnya skala bawaan → `{field: 'primary', ratio ≈ 4,47}`; skala accent buatan dengan 500 = `#5a5a5a` dan primary kosong (2,74:1 vs `#000a3c`) → `{field: 'accent', ratio ≈ 2,74}`; skala bawaan `app.css` untuk keduanya → `null`. Primary-950 efektif dipakai: accent yang sama diuji terhadap langkah 950 skala primary buatan bila primary diisi.
- AC-6 Penolakan di Action: dengan `BuildColorScale` diganti fake di container yang mengembalikan skala gagal AC-5, `UpdatePortalTheme` melempar `ValidationException` di `primary` (pesan memuat `4,4:1` dan `4,5:1`) atau `accent` (memuat `2,7:1`), dan tidak ada setting tersimpan. Dengan skala asli, masukan yang dulu "berisiko" diterima: primary `#7a7a7a`, `#ffff00`, aksen `#7a5700`.
- AC-7 Skala: `BuildColorScale` mengembalikan tepat kunci 50,100,…,950, tiap nilai `^#[0-9a-f]{6}$`; anchor persis: primary `#000a3c`→950, `#061247`→900, `#15803d`→500; accent `#f8b800`→500; input huruf besar menghasilkan keluaran huruf kecil.
- AC-8 Jaminan kontras skala: untuk sampel primary (`#000a3c`, `#1f2a5e`, `#15803d`, `#c00000`, `#767676`, `#000000`, `#0000ff`, `#ffff00`) × aksen (`#f8b800`, `#ffff00`, `#ffffff`, `#22d3ee`, `#7a5700`), `contrastFailure()` atas skala hasil `BuildColorScale` = `null`.
- AC-9 CSS token: dengan keempat key valid, `css` diawali `:root{`, memuat 11 `--color-primary-*`, 11 `--color-accent-*`, `--font-sans: 'Nunito'`, `--radius-xl: 1.25rem`, dan cocok `/^:root\{[^<>{}\\@]*\}$/` tanpa `url(`. Hanya primary diisi → tidak ada `--color-accent-`, `--font-`, `--radius-`. Font `lora` → `--font-heading` saja, aliases `['plus-jakarta-sans','lora','noto-naskh-arabic']`. (Test memakai manifest fiktif berisi alias `plus-jakarta-sans`, `lora`, `nunito`, `noto-naskh-arabic` di public path sementara, `app()->usePublicPath()`.)
- AC-10 Nilai DB rusak: `Setting::set` langsung `theme.primary = '#000000;}</style><script>alert(1)</script>'`, `theme.primary = "#1f2a5e\n"`, `theme.accent = ['x']`, `theme.font = 'x'`, `theme.radius = 'large;}'` → `css = null`, `font_aliases = null`; HTML beranda tidak memuat `alert(1)` atau `</style><script`.
- AC-11 Render publik: dengan tema valid, beranda, halaman PSB (`psb.*`), dan 404 memuat `<style data-portal-theme>` dengan `--color-primary-900:`; `@fonts` (fake `Vite`) menerima `['nunito','noto-naskh-arabic']`.
- AC-12 Alias font hilang: `theme.font = nunito` dengan manifest fiktif tanpa alias `nunito` (dan tanpa berkas manifest sama sekali) → `font_aliases = null`, `css` tanpa `--font-sans`/`--font-heading` (warna/radius tetap ada bila diisi); beranda 200 dengan layout asli `Vite` (tanpa fake) dan manifest fiktif itu, tanpa `ViteException`.
- AC-13 Panel tidak berubah: dengan tema valid, HTML `/admin/login` dan `/admin/site/theme` tidak memuat `data-portal-theme`; warna panel tetap `Color::Emerald` (test AC-17 F035 tetap lulus).
- AC-14 Akses halaman: `/admin/site/theme` 200 untuk super-admin, admin-sekolah, admin-konten; 403 untuk bendahara, admin-psb, kesantrian, musyrif, petugas-pos, wali. Label "Tampilan" tampil di grup Situs setelah Footer.
- AC-15 Form Filament: terisi dari settings (key kosong → warna kosong, font `jakarta`, radius `medium`); `save` dengan primary `red` menampilkan galat di `data.primary`; simpan valid memanggil Action dan mengirim notifikasi "Tampilan portal disimpan.".

## Rencana test
- `tests/Feature/Actions/Konten/BuildColorScaleTest.php`: AC-5, AC-7, AC-8.
- `tests/Feature/Actions/Konten/UpdatePortalThemeTest.php`: AC-2, AC-3, AC-4, AC-6.
- `tests/Feature/Actions/Konten/GetPortalThemeTest.php`: AC-1 (bagian Action), AC-9, AC-10 (bagian Action), AC-12 (bagian Action).
- `tests/Feature/Http/Portal/PortalThemeTest.php`: AC-1 (HTML), AC-10 (HTML), AC-11, AC-12 (HTML), AC-13.
- `tests/Feature/Filament/Pages/ThemePageTest.php`: AC-14, AC-15.
- Ubah `tests/Feature/Filament/Pages/SiteNavigationGroupsTest.php` (urutan grup Situs: Beranda, Navigasi, Footer, **Tampilan**, Halaman, …) oleh red-tester di mode RED.

## Urutan implementasi
Paket 1 — data + Action (hijau: `BuildColorScaleTest`, `UpdatePortalThemeTest`, `GetPortalThemeTest`)
1. `BuildColorScale` (OKLCH, tangga, anchor, gamut, `contrast`, `contrastFailure`). 2. `GetPortalTheme` (KEYS, peta FONTS/RADII, validasi ulang, cek alias di manifest, CSS) + tambah ke `GetSchoolProfile::KEYS`. 3. `UpdatePortalTheme` + kunci galat `lang/id`.
Paket 2 — aset + layout publik (hijau: `PortalThemeTest`, `PortalVisualIdentityTest`, `PortalFontsTest`)
4. `vite.config.js` Lora/Nunito (`preload: false`), `npm run build`, cek manual alias `lora`/`nunito` di `fonts-manifest.json` dan test F035 AC-10/AC-16 tetap lulus. 5. Aturan `--font-heading` dan komentar larangan `@theme inline` di `app.css`. 6. Layout publik: `$theme`, `@fonts($aliases)`, `<style data-portal-theme>`.
Paket 3 — Filament + dokumen (hijau: `ThemePageTest`, `SiteNavigationGroupsTest`, `FooterPageTest`)
7. Halaman `Site/Theme` + teks `lang/id`. 8. Design guide §2/§3. 9. Pint; cek visual 360px/1024px/desktop dengan bawaan dan satu tema.

## Risiko & pertanyaan terbuka
- `bunny()` (laravel-vite-plugin 3.x) **mengunduh font saat build** ke `public/build/assets/*.woff2|woff` dan menulis `fonts-manifest.json`; `@fonts` menyisipkan `@font-face` dari manifest. Tidak ada CDN saat runtime (CSP `font-src 'self'` sudah memaksanya). Tidak perlu rencana self-host tambahan.
- `@fonts($aliases)` melempar `ViteException` bila alias tidak ada di manifest. Ditangani: `GetPortalTheme` hanya memberi alias yang ada di manifest; selain itu `null` (AC-12).
- Warna masukan tidak selalu tampil di tombol: tombol memakai langkah 900 skala (gelap), warna masukan terang hanya muncul di langkah muda. Ini konsekuensi aturan kontras; dijelaskan di helper form.
- Penolakan kontras praktis tidak terjadi untuk masukan nyata (tangga L tetap). Kriteria issue "divalidasi kontras 4,5:1" dipenuhi oleh jaminan skala (AC-8) dan jaring pengaman (AC-5/AC-6); orkestrator dapat mencatat ini di issue bila perlu.
- Preset #25 yang menulis lewat `Setting::set` (bukan Action) tidak menormalkan nilai bawaan ke null; dampaknya hanya CSS redundan (catatan untuk #25).
- Utilitas berwarna dengan opasitas punya fallback hex statis (`#f8b80066`) untuk peramban tanpa `color-mix`; peramban lama itu tetap melihat emas bawaan untuk ring transparan. Diterima.
- Bayangan `--shadow-*` tetap bernada navy. Diterima (YAGNI); preset #25 boleh menambah.
- Cache: tidak ada cache aplikasi untuk portal; `Setting` cache per request. Perubahan terlihat setelah `max-age` HTTP portal (bawaan 300 detik), sama dengan setting portal lain; ETag berubah karena isi HTML berubah.
- Keputusan: tetap tiga pilihan font; font judul kondensasi berhuruf kapital (Barlow Condensed di mockup) tidak dipakai karena bertentangan dengan design guide §9.
- Menyimpang dari design guide §3 ("jangan menambah font lain"): diperbarui di langkah 8 sesuai issue.

## Riwayat revisi
- 21 Okt 2026: DRAFT pertama.
- 21 Okt 2026, revisi 1 (review #1): (1) major alias font: `GetPortalTheme` memeriksa alias di manifest aktif, font bawaan → `font_aliases = null` (`@fonts` sama dengan sekarang), alias hilang → font bawaan tanpa exception; AC-12 baru. (2) major kontras: satu aturan `contrastFailure()` atas hasil skala (primary 700/800/900/950 vs putih dan 100/200 vs 950; accent 400/500 vs primary-950 efektif), dipakai Action dan pembaca; AC-5/AC-6 ditulis ulang dengan skala buatan (`#777777` 4,47:1, `#5a5a5a` 2,74:1), contoh `#7d7d7d`/`#141414` dibuang, AC-8 memakai aturan yang sama. Minor: regex `\z`, validasi ulang DB tanpa `trim` (kasus `"#1f2a5e\n"` di AC-10); AC-15 lama (string config) dibuang, cek alias jadi langkah manual 4; AC-13 (panel) cukup penanda `data-portal-theme`; komentar larangan `@theme inline` di `app.css`; keputusan tiga font dicatat. AC dinomori ulang (AC-12 baru, panel AC-13, akses AC-14, form AC-15).

## Review #1 — 2026-10-21

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | Risiko, `@fonts($aliases)`, AC-11 | Alias font yang hilang dari `fonts-manifest.json` melempar `ViteException` di layout publik: seluruh portal (beranda, PSB, 404) jadi 500 bila admin memilih font yang belum ada di build (build lama, unggah parsial di cPanel, dev tanpa `npm run build`). Plan hanya menyebut "cukup `npm run build`" dan tidak ada AC. Di test aman karena `portalBoot` mengarah ke build dir fiktif, jadi celah ini tidak tertangkap. | Jangan biarkan pilihan admin mematikan situs publik. `GetPortalTheme` (atau helper kecil) memeriksa alias ada di manifest build aktif; bila tidak, pakai font bawaan (tanpa CSS font, aliases bawaan) tanpa exception. Tambah satu AC dengan manifest fiktif tanpa alias `nunito`. |
| 2 | major | Action `UpdatePortalTheme` (kontras aksen), AC-6 | Kontras aksen diuji pada warna masukan, padahal teks `text-primary-950` duduk di langkah 400/500 hasil skala, dan tangga L tetap kecuali di langkah anchor. Aksen gelap (mis. `#7a5700`, anchor 800) ditolak padahal 400/500-nya tetap terang; aksen `#7d7d7d` diterima padahal ia anchor di 800. Pesan "pilih lebih terang" menyesatkan, dan AC-6 mengunci perilaku yang tidak konsisten dengan AC-8. Hal yang sama berlaku ringan untuk primary vs putih (hanya benar karena anchor). | Satu aturan validitas atas hasil `BuildColorScale`, sama dengan jaminan AC-8: primary valid bila putih vs 700/800/900/950 ≥ 4,5 dan 100/200 vs 950 ≥ 4,5; aksen valid bila 400/500 vs primary-950 efektif ≥ 4,5. Tetap pakai satu method bersama untuk Action dan `GetPortalTheme`. Sesuaikan AC-5/AC-6 dengan aturan itu dan buang pasangan contoh `#7d7d7d`/`#141414`. |
| 3 | minor | Sanitasi (a), `^#[0-9a-fA-F]{6}$` | Sanitasi sudah kuat: hanya hex hasil `BuildColorScale` dan peta server masuk ke `<style>`, nilai DB rusak diabaikan per key (AC-9/AC-10 memadai). Celah kecil: `$` PHP cocok sebelum newline akhir; validasi ulang nilai DB tidak melewati `trim`. | Pakai `\z` atau flag `D`; validasi ulang DB tanpa `trim`. |
| 4 | minor | Default (b) | Tanpa `<style>` dan aturan `h1,h2,h3{font-family:var(--font-heading,var(--font-sans))}` menghasilkan font yang sama dengan sekarang (utilitas `font-arabic` di `quote.blade.php` ada di layer utilities sehingga tidak tertimpa). `@fonts` tanpa argumen vs `['plus-jakarta-sans','noto-naskh-arabic']` ekuivalen bila manifest berisi keduanya. | Cukup catat; AC-1 sudah mengunci. |
| 5 | minor | Preset #25 (c) | Tidak menghalangi: preset menulis empat key dan `GetPortalTheme` memvalidasi ulang. Bila preset menulis lewat `Setting::set` (bukan `UpdatePortalTheme`), nilai sama-dengan-bawaan tidak dinormalkan ke null, tapi dampaknya hanya CSS redundan. | Cukup catat di #25. |
| 6 | minor | Tailwind v4 (f) | Terverifikasi: `app.css` memakai `@theme` biasa (bukan `inline`), jadi `bg-primary-*`, `rounded-lg/xl` (13 dan 25 pemakaian di view portal, tidak ada `rounded-2xl`/`md`), dan modifier opasitas (`color-mix(... var(--color-x) N%, transparent)`) memakai `var()` tanpa build ulang. Tidak ada hex navy/emas hardcode di `resources/views` di luar `app.css`. Fallback hex statis untuk peramban tanpa `color-mix` sudah diterima di plan. Risiko: seseorang kelak mengganti ke `@theme inline` atau `theme()` dan tema diam-diam mati. | Tambahkan komentar di `app.css` bahwa blok `@theme` tidak boleh `inline`; tidak perlu test tambahan. |
| 7 | minor | Ukuran/AC (d) | Ukuran plan wajar (±90 baris, 15 AC). AC-15 menguji isi `vite.config.js`/`app.css` lewat string (rapuh, nilainya rendah selain AC-4 F035); AC-8 dengan 11 sampel warna sudah cukup tanpa tambahan; AC-12 "tanpa hex hasil skala" cukup assert `data-portal-theme` tidak ada. | Pertimbangkan menggabung AC-15 ke langkah 4 (cek manual alias di manifest) dan menyederhanakan AC-12. |

Kesesuaian issue: keempat kriteria tercakup (kontras + skala PHP: AC-5..8; 3 font: AC-9/15; variabel CSS tanpa build: AC-9/11; panel tidak berubah: AC-12). Dependensi tidak ada. CLAUDE.md: tanpa Node runtime, teks di `lang/id`, Action per operasi, tanpa migration, sesuai. Pertanyaan font kondensasi (Barlow) dibiarkan sebagai catatan, sesuai YAGNI.
