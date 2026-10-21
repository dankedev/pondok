# F071 portal-warna-merek-terlihat — Warna merek pilihan admin terlihat di tombol dan judul portal

Issue: #52
Status rencana: APPROVED (orkestrator, setelah revisi 1)

## Ringkasan & tujuan
F070 menghitung skala 50–950 dari warna utama admin, tetapi tombol, elemen aktif, dan judul portal memakai langkah 900/950 secara tetap, sehingga hijau `#15803d` tampil hampir hitam. F071 menambah empat token semantik "merek" yang bawaannya sama persis dengan tampilan sekarang, dan bila warna utama diatur, token itu diisi warna pilihan admin (atau langkah terdekat yang tetap 4,5:1). Tanpa build ulang saat admin mengganti warna (cukup sekali `npm run build` saat rilis fitur ini), tanpa migration, panel Filament tidak berubah.

## Ruang lingkup
- Termasuk: token `--color-brand`, `--color-brand-hover`, `--color-on-brand`, `--color-brand-text` di `@theme`; fungsi pemetaan murni; penulisan token oleh `GetPortalTheme`; penggantian kelas di Blade portal/PSB (kedua preset F072 bila sudah masuk `main`); keterangan + pratinjau di Situs > Tampilan; design guide §2.
- Tidak termasuk (eksplisit): warna aksen (tetap skala F070); footer dan CTA `navy` (`bg-primary-950` teks putih), CTA `gold`, tautan `text-primary-700`, eyebrow, badge, nav aktif (`bg-primary-50 text-primary-900`, sudah bernada warna admin lewat skala), border hover chip/paginasi, ring fokus `primary-500`, `shadow-button` navy, kutipan Arab, `errors/layout.blade.php`, panel admin. Tidak ada setting baru, tidak ada aturan "isi tombol harus kontras 3:1 terhadap latar" (lihat Risiko).

## Model data
Tanpa migration, tanpa key baru. Sumber tetap `theme.primary` (F070).

`resources/css/app.css`, di `@theme` setelah skala primary (keputusan: `var()`, bukan hex literal, agar bawaan identik dengan `primary-900/800/950` tanpa duplikasi nilai, dan utilitas lama tidak berubah):
```
--color-brand: var(--color-primary-900);
--color-brand-hover: var(--color-primary-800);
--color-on-brand: var(--color-white);
--color-brand-text: var(--color-primary-950);
```
Dengan tema kustom keempat token selalu ditulis eksplisit oleh server, jadi rujukan `var()` hanya berlaku untuk bawaan.

## Action class
1. `BuildColorScale::brandColors(string $hex, array $scale): array{brand: string, brand_hover: string, on_brand: string, brand_text: string, fill_adjusted: bool, text_adjusted: bool}` — static, murni (KISS: memakai `contrast()`/`STEPS` di kelas yang sama; tidak perlu Action baru). `$hex` huruf kecil, `$scale` hasil `handle($hex, 'primary')` sehingga `$hex` = langkah anchor `k`. Teks gelap `D` = `$scale[950]`. Lolos = kontras ≥ `MIN_CONTRAST` (4,5).
   - **brand (isi):** `$hex` bila lolos dengan putih atau dengan `D`. Kalau tidak, langkah skala terdekat dari `k` (jarak indeks) yang lolos dengan putih atau `D`; seri → lebih gelap; `fill_adjusted = true`. Selalu ada (langkah 700–950 lolos putih, dijamin `contrastFailure`).
   - **on_brand:** `#ffffff` bila lolos dengan brand, selain itu `D`.
   - **brand_hover:** dihitung dari indeks `j` langkah **brand terpilih** (bukan anchor; sama dengan `k` bila tidak digeser). Teks putih → `$scale[j+1]` (lebih gelap); teks gelap → `$scale[j-1]` (lebih terang). Bila langkah itu tidak ada (ujung skala) atau kontrasnya dengan on_brand < 4,5 → tetangga di arah lain bila lolos dengan on_brand; bila keduanya tidak lolos → sama dengan brand. Dengan skala nyata hover selalu berbeda dari brand.
   - **brand_text (teks berwarna di atas putih):** `$hex` bila lolos dengan putih; kalau tidak, langkah pertama yang lebih gelap dari `k` yang lolos; `text_adjusted = true`.
2. `GetPortalTheme::brandTokens(mixed $hex, ?array $accentScale = null): ?array` — static, **satu titik bersama** untuk portal dan pratinjau admin. Mengembalikan null bila `$hex` bukan hex (`isHex`) atau `contrastFailure($primaryScale, $accentScale ?? BuildColorScale::DEFAULT_ACCENT)` tidak null (skala dibangun lewat `app(BuildColorScale::class)` agar bisa dipalsukan di test, `$hex` di-lowercase); selain itu hasil `brandColors()`.
3. `GetPortalTheme::handle()` — bila skala primary kustom bertahan setelah loop `contrastFailure` yang ada, panggil `brandTokens($primary, $scales['accent'] ?? null)` (pasti non-null karena kondisi sama) dan tambahkan `--color-brand`, `--color-brand-hover`, `--color-on-brand`, `--color-brand-text`. Primary bawaan/rusak/gagal → keempat token tidak ditulis; semua bawaan → `css` tetap `null`. Nilai = hex hasil hitungan (sanitasi F070 tidak berubah). `UpdatePortalTheme` tidak berubah.

## UI (Filament / Blade)
- Blade (rujukan design guide §2, §6; cari ulang lokasi setelah rebase: blok beranda mungkin pindah ke `resources/views/themes/{default,centered}/blocks/` oleh PR #54, perubahan berlaku untuk **semua** preset):
  - `components/ui/button.blade.php` varian bawaan: `bg-brand text-on-brand hover:bg-brand-hover` (ring fokus dan `shadow-button` tetap).
  - `portal/partials/nav-links.blade.php` tombol header: kelas sama dengan tombol.
  - `components/portal/filter-chips.blade.php` chip aktif dan `portal/partials/pagination.blade.php` halaman aktif: `bg-brand text-on-brand`.
  - `components/ui/steps.blade.php` batang langkah selesai/aktif: `bg-brand`.
  - `livewire/psb/registration-form.blade.php` radio/checkbox: `accent-brand`.
  - `text-primary-950` → `text-brand-text` hanya pada: h1 hero (semua preset), h1 `portal/home.blade.php` tanpa hero, nama/nama pendek sekolah di header `layouts/public.blade.php` (latar putih).
- `app/Filament/Pages/Site/Theme.php`: `ColorPicker primary` dan `accent` → `->live(onBlur: true)` (atau `->live(debounce: 500)` bila onBlur tidak memicu dari picker); di bawahnya satu komponen tampilan (View Blade kecil `filament/pages/site/theme-brand-preview.blade.php`), terlihat hanya bila `GetPortalTheme::brandTokens($get('primary'), skala aksen dari `$get('accent')` bila hex, selain itu null)` non-null. Bila primary hex tetapi `brandTokens` null (gagal kontras), tampil kalimat `brand_preview.rejected` ("Warna ini tidak memenuhi kontras; tombol dan judul tetap memakai warna bawaan.") tanpa contoh tombol/judul, berisi pratinjau: contoh tombol (`style` background brand, warna teks on_brand, teks "Contoh tombol") dan contoh judul (warna brand_text), plus keterangan teks: warna tombol dan judul yang dipakai (hex), dan bila `fill_adjusted`/`text_adjusted` kalimat "disesuaikan agar teks tetap terbaca (kontras minimal 4,5:1)". Hex hasil server saja yang masuk ke `style`. Helper `color_helper` diperbarui (tidak lagi menyebut "langkah gelap").
- `lang/id/pondok.php`: `school_profile.theme.brand_preview.{heading, button_sample, title_sample, button_uses, title_uses, adjusted_button, adjusted_title, rejected}` dan `color_helper` baru.
- `docs/design-guide.md` §2: ganti kalimat "Karena itu warna masukan terang tidak muncul di tombol (tombol memakai langkah 900)" dengan aturan token merek (empat token, bawaan, aturan pemetaan, elemen yang memakainya, yang tetap navy, serta catatan eksplisit bahwa aksen dan tautan `text-primary-700` tetap memakai skala F070); diperbarui di commit yang sama dengan kode. Ikuti §0/§13 sebelum selesai.

## Permission & cakupan
Tanpa permission baru (`view:school-profile` dari F070). Tanpa `unit_user`, tanpa saklar modul.

## Kriteria penerimaan (bisa dites)
- AC-1 Warna sedang: `brandColors('#15803d', skala)` → brand `#15803d`, on_brand `#ffffff`, brand_text `#15803d`, brand_hover = `$scale[600]` (langkah brand 500 + 1, lebih gelap), kedua penanda `false`.
- AC-2 Warna terang: `#facc15` → brand `#facc15`, on_brand = `$scale[950]` (kontras ≥4,5 dengan brand), brand_hover = langkah brand − 1 (lebih terang; brand = anchor karena tidak digeser), kontras dengan on_brand ≥4,5, `fill_adjusted = false`; brand_text ≠ `#facc15`, berada di skala pada langkah lebih gelap dari anchor, kontras dengan putih ≥4,5, dan langkah tepat di atasnya (bila lebih gelap dari anchor) < 4,5; `text_adjusted = true`.
- AC-3 Warna gelap: `#1a2c72` → brand/brand_text `#1a2c72`, on_brand `#ffffff`, hover `$scale[800]` (langkah brand 700 + 1), penanda `false`. `#000a3c` dan `#000000` (langkah brand 950, ujung) → hover = `$scale[900]` (arah lain), on_brand putih.
- AC-4 Isi digeser: `#777777` (4,48:1 vs putih, <4,5 vs `$scale[950]`) → brand = `$scale[500]` (tetangga 300 dan 500 sama-sama lolos, seri → lebih gelap), on_brand `#ffffff`, brand_hover = `$scale[600]` (dari langkah brand 500, bukan anchor 400), `fill_adjusted = true`. `#ffffff` → brand `#ffffff`, on_brand `$scale[950]`, hover `$scale[100]`, `text_adjusted = true`.
- AC-5 Jaminan: untuk sampel `#15803d`, `#facc15`, `#1a2c72`, `#000a3c`, `#777777`, `#808080`, `#ffffff`, `#000000`, `#0000ff`, `#ffff00`, `#c00000`, `#22d3ee`: semua keluaran `^#[0-9a-f]{6}$`; contrast(brand, on_brand) ≥4,5; contrast(brand_hover, on_brand) ≥4,5; contrast(brand_text, `#ffffff`) ≥4,5; brand, brand_hover, brand_text ∈ nilai skala; on_brand ∈ {`#ffffff`, `$scale[950]`}.
- AC-6 Token ditulis: `theme.primary = #15803d` → `css` memuat `--color-brand: #15803d;`, `--color-on-brand: #ffffff;`, `--color-brand-text: #15803d;`, `--color-brand-hover:` dengan hex, dan tetap cocok `/^:root\{[^<>{}\\@]*\}$/`.
- AC-7 Bawaan tetap: tanpa key `theme.*` → `css = null`; hanya accent/font/radius diisi → `css` tanpa `--color-brand`; primary rusak (mis. `#000000;}`) atau gagal kontras (skala palsu AC-5 F070) → tanpa `--color-brand`.
- AC-8 Token bawaan `app.css`: blok `@theme` memuat tepat `--color-brand: var(--color-primary-900)`, `--color-brand-hover: var(--color-primary-800)`, `--color-on-brand: var(--color-white)`, `--color-brand-text: var(--color-primary-950)`.
- AC-9 Kelas di view: berkas portal/PSB (`identitasViews()` dari `PortalVisualIdentityTest`, plus `resources/views/themes` bila ada) tidak lagi memuat kelas utuh `bg-primary-900`, `hover:bg-primary-800`, `accent-primary-900` (dicocokkan sebagai token kelas `/(?<![\w:-])bg-primary-900(?![\w-])/` dst., sehingga `hover:border-primary-900`, `hover:text-primary-950`, `bg-primary-950`, `bg-primary-50 text-primary-900` yang sengaja tetap tidak ikut); `components/ui/button.blade.php` varian bawaan memuat `bg-brand text-on-brand hover:bg-brand-hover`; setiap blade hero memuat `text-brand-text` pada `<h1`.
- AC-10 Render: dengan `theme.primary = #15803d` dan blok hero bertombol, `GET /` memuat `--color-brand: #15803d` di `<style data-portal-theme>`, `<h1` dengan `text-brand-text`, tombol hero dengan `bg-brand`, dan nama sekolah header dengan `text-brand-text`; halaman PSB daftar memuat `accent-brand`.
- AC-11 Panel tidak berubah: dengan `theme.primary` diisi, `/admin/site/theme` tidak memuat `data-portal-theme` maupun `--color-brand`; warna panel tetap `Color::Emerald` (test F035/F070 tetap lulus).
- AC-12 Pratinjau admin: `Livewire::test(Theme::class)` dengan primary kosong → teks `brand_preview.heading` tidak tampil; `fillForm(['primary' => '#15803d'])` (tanpa simpan) → tampil heading, "Contoh tombol", `#15803d`, tanpa kalimat `adjusted_*`; `#facc15` → tampil `adjusted_title` dengan hex brand_text dan tanpa `adjusted_button`; `#777777` → tampil `adjusted_button`. Nilai `red` → pratinjau tidak tampil, tanpa error.
- AC-13 Tanpa pratinjau palsu: `GetPortalTheme::brandTokens()` → null untuk `red`, null, `#fff`; dengan `BuildColorScale` palsu di container yang mengembalikan skala gagal (langkah 900 = `#777777`, pola AC-5 F070) → null untuk `#15803d`, dan pada halaman Tampilan `fillForm(['primary' => '#15803d'])` menampilkan `brand_preview.rejected` tanpa "Contoh tombol"; `GetPortalTheme::handle()` dengan setting yang sama tidak menulis `--color-brand`.

## Rencana test
- `tests/Feature/Actions/Konten/BuildColorScaleTest.php` (tambah): AC-1–AC-5.
- `tests/Feature/Actions/Konten/GetPortalThemeTest.php` (tambah): AC-6, AC-7.
- `tests/Feature/Http/Portal/PortalBrandColorTest.php` (baru): AC-8, AC-9 (sumber), AC-10, AC-11.
- `tests/Feature/Filament/Pages/ThemePageTest.php` (tambah): AC-12, AC-13 (halaman).
- `tests/Feature/Actions/Konten/GetPortalThemeTest.php` juga AC-13 (`brandTokens`, `handle`).
- Cek manual (bukan test): 360px, 1024px, desktop dengan `#facc15`, `#15803d`, `#1a2c72`, dan bawaan; beranda (kedua preset), daftar berita (chip, paginasi), PSB (langkah, radio).

## Urutan implementasi
Paket 1 — fungsi + token server (hijau: `BuildColorScaleTest`, `GetPortalThemeTest`, `UpdatePortalThemeTest`)
1. `BuildColorScale::brandColors()`. 2. `GetPortalTheme::brandTokens()` + `handle()` menulis empat token.
Paket 2 — CSS + Blade (hijau: `PortalBrandColorTest`, `PortalVisualIdentityTest`, `PortalThemeTest`, `HomeBlocksTest`, test preset F072 bila ada)
3. Token `@theme` di `app.css`, `npm run build`, lalu verifikasi CSS terkompilasi di `public/build/assets/*.css` memuat `--color-white`, `--color-primary-800`, `--color-primary-900`, `--color-primary-950` dan `--color-brand*` (Tailwind v4 hanya memancarkan token yang dipakai). Bila ada yang hilang, tulis token merek sebagai hex literal (`#061247`, `#0f1d59`, `#ffffff`, `#000a3c`) atau pakai `@theme static`; catat pilihan di laporan. 4. Ganti kelas di Blade (semua preset). 
Paket 3 — Filament + dokumen (hijau: `ThemePageTest`, `SiteNavigationGroupsTest`)
5. Pratinjau di `Theme.php` + view + `lang/id`. 6. Design guide §2. 7. Pint; cek visual.

## Risiko & pertanyaan terbuka
- Konflik dengan PR #54 (F072) di `GetPortalTheme`, `Theme.php`, `lang/id`, view blok. Rebase ke `main` setelah #54 masuk sebelum Paket 2/3; cari ulang semua `bg-primary-900`/`text-primary-950` di hero lewat grep, jangan bergantung pada path.
- Warna terang (mis. kuning) berarti tombol berisi terang dengan teks gelap di latar putih; tepi tombol bisa samar (bukan pelanggaran kontras teks). Di CTA `gold` (latar `accent-500`) tombol utama bisa menyatu bila admin memilih kuning. Diterima sebagai pilihan admin dan terlihat di pratinjau; aturan 3:1 isi-vs-latar tidak dibuat (YAGNI, akan menggeser hampir semua warna terang dan menggagalkan tujuan issue).
- Token `var()` bergantung pada token rujukan ikut dipancarkan; ditangani langkah verifikasi build (langkah 3).
- Pemetaan dihitung ulang di setiap render portal (murni, ±11 kontras); biaya diabaikan.
- Nilai contoh AC-4 (`#777777` → langkah 500) dihitung manual dari tangga F070; red-tester memastikan dengan prototipe, dan bila anchor berbeda, AC mengikuti aturan (tetangga terdekat yang lolos, seri → lebih gelap), bukan angka langkah.

## Riwayat revisi
- 21 Okt 2026: DRAFT pertama.
- 21 Okt 2026, revisi 1 (Review #1): (1) major: `GetPortalTheme::brandTokens()` jadi satu titik untuk portal dan pratinjau, menolak hex yang skalanya gagal `contrastFailure`; pratinjau menampilkan `rejected`; AC-13 baru. (2) major: hover dihitung dari langkah brand terpilih; AC-1–AC-4 diperbarui, `#777777` → hover `$scale[600]`. Minor: verifikasi build token (langkah 3), frasa "tanpa build ulang saat admin mengganti warna", AC-9 mencocokkan kelas utuh dan mencatat kelas yang tetap, `->live(onBlur: true)`, design guide §2 mencatat aksen dan tautan tetap skala F070.

## Review #1 — ringkasan
Verdict: CHANGES_REQUESTED (0 blocker, 2 major, 5 minor), semua ditangani di revisi 1.
1. major — pratinjau hanya digate `isHex`, bisa menampilkan warna yang ditolak portal → `brandTokens()` bersama + AC-13.
2. major — hover dari anchor, bukan langkah brand terpilih → aturan hover dan AC-1–AC-4.
3. minor — verifikasi token terpancar setelah build → langkah 3.
4. minor — frasa "tanpa build ulang aset" → Ringkasan.
5. minor — AC-9 substring → kelas utuh.
6. minor — `->live()` tiap ketukan → `onBlur`/debounce.
7. minor — design guide §2 catat aksen/tautan tetap F070 → bagian UI.
