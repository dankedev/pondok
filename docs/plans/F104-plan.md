# F104 konten-tema-warna-font — Tema portal menentukan warna dan tipografi

Issue: #140
Status rencana: DRAFT (revisi 1)
PR ditujukan ke `dev` (cabang `feat/f104-warna-tipografi-tema`), memakai `Refs #140`, bukan `Closes`.
Jalur: penuh (skema dan validator tema).

## Ringkasan & tujuan
Skema tema F102 hanya mengatur struktur. Tema resmi belum bisa tampil beda warna dan huruf (mis. Amanah: hijau-krem dengan judul serif). Perluasan ini menambah **tiga kunci puncak opsional berupa enum** pada `theme.json`: `palette`, `surface`, `font`. Tetap data saja: nilai dipetakan server ke token yang sudah ada, tanpa hex, nama font, CSS, atau berkas dari tema.

## Temuan penting (mengubah usulan awal)
Situs sudah punya kontrol warna dan font dari admin (F070, Situs > Tampilan): `theme.primary`, `theme.accent`, `theme.font` (`jakarta|lora|nunito`), `theme.radius`, diolah `GetPortalTheme` + `BuildColorScale` (skala 50-950, guard kontras 4,5:1) menjadi `<style data-portal-theme>:root{...}`. Maka F104 **tidak** membuat mesin warna/font baru; tema hanya menyuplai **nilai bawaan** ke mesin yang sama:
- **Font:** `font` memakai kunci `GetPortalTheme::FONTS` yang ada (`jakarta`, `lora`, `nunito`; semuanya sudah ada di build, `noto-naskh-arabic` tetap font Arab). Tidak ada pasangan font baru.
- **Warna:** `palette` = nama yang dipetakan ke sepasang hex (primer, aksen) yang diolah `BuildColorScale`. `surface` = nama yang menimpa `--color-gray-50` dan `--color-gray-100` (permukaan band `tone: muted`/`auto`, kartu) dengan tint hangat; `--color-white` tidak disentuh (teks putih tetap putih).
- **Prioritas:** nilai admin yang terisi (`theme.primary`, `theme.accent`, `theme.font`) menang atas tema; nilai admin kosong (null = bawaan) mengikuti tema. Konsekuensi (diterima, KISS): admin tidak bisa "memilih Jakarta secara eksplisit" di atas tema berfont Lora, karena bawaan disimpan null. `theme.radius` tidak dipengaruhi tema.

## Keputusan versi skema
**Tambahan kompatibel, `schema_version` tetap 1, `ValidatePortalTheme::CORE_VERSION` naik `1.0.0` -> `1.1.0`.** Alasan: kebijakan `docs/rilis.md` (versi naik hanya untuk perubahan merusak; tambahan = `min_core`); semua kunci baru opsional sehingga tema v1 lama (termasuk Amanah/Lapang 1.0.0 dan tanda tangannya, yang menandatangani byte berkas) tetap valid tanpa diubah dan tanpa `MigrateThemeSchema` (tetap kerangka kosong). Tema yang memakai kunci baru wajib `min_core >= 1.1.0` (validator menolak bila kurang); inti lama (1.0.0) menolaknya lewat kunci tak dikenal/`min_core`, jatuh ke chrome inti.

## Ruang lingkup
- Termasuk: tiga kunci enum + validator, `ThemePalette` (peta nama -> token), integrasi di `GetPortalTheme`, `CORE_VERSION` 1.1.0, test (termasuk kontras per palet), `docs/rilis.md`, `docs/design-guide.md` (§2 dan §6 satu paragraf), `lang/id`.
- Tidak termasuk: hex/nama font/URL font bebas dari tema, pengubahan `bg-white` body, `--color-white`, warna status (success/warning/danger/info), radius dari tema, font baru di build, perubahan UI Situs > Tampilan selain satu catatan "warna/font bawaan mengikuti tema", penerbitan ulang Amanah/Lapang (repo `pondok-themes-work`, di luar repo ini), semua hal F103.

## Skema tambahan
Kunci puncak opsional (set kunci tertutup tetap): 
- `palette`: `navy` (bawaan inti; tanpa deklarasi) | `hijau` | `biru`.
- `surface`: `putih` (bawaan; tanpa deklarasi) | `krem`.
- `font`: `jakarta` | `lora` | `nunito` (kunci `GetPortalTheme::FONTS`; nilai lain ditolak, satu sumber kebenaran lewat `array_keys`).

Peta di `app/Actions/Konten/ThemePalette.php` (konstanta, hex literal, tanpa interpolasi):
- `PALETTES`: `hijau` => primer `#...`, aksen `null` (emas bawaan); `biru` => primer `#...`, aksen `null`; hex final dipilih green-coder sampai lolos AC-3.
- `SURFACES`: `krem` => `['--color-gray-50' => '#...', '--color-gray-100' => '#...']`.

Penerapan di `GetPortalTheme::handle()`: tema aktif dibaca dari `ResolveActiveTheme` (memoisasi per request, tanpa query baru); `primary` efektif = `theme.primary` admin ?? primer palet tema; `accent` efektif sama; `font` efektif = `theme.font` admin ?? `font` tema; deklarasi `surface` ditambahkan ke `:root{}`. Setelahnya jalur lama tidak berubah (guard kontras, `brandTokens`, cek `fontsAvailable`, `<style data-portal-theme>`). Tema nonaktif/rusak/null = perilaku sekarang persis. Semua bawaan = `css` null seperti sekarang.

## Kriteria penerimaan
- **AC-1 Kompatibilitas:** tema v1 tanpa tiga kunci baru (`min_core` 1.0.0) tetap lolos validator dan keluaran `GetPortalTheme` identik dengan sebelum F104; tema resmi `default`, `centered`, `majalah`, `mushaf` tidak diubah.
- **AC-2 Validator:** `palette`, `surface`, `font` menerima hanya nilai enum (string); nilai lain (angka, hex, array, kosong, nama font bebas, `url(...)`, huruf besar) ditolak dengan pesan Indonesia; kunci baru dengan `min_core` < 1.1.0 ditolak; `min_core` 1.1.0 dengan kunci baru diterima; `min_core` 1.2.0 ditolak (`CORE_VERSION` 1.1.0).
- **AC-3 Kontras palet:** untuk setiap palet (`hijau`, `biru`) skala hasil `BuildColorScale` bersama aksen bawaan lolos `contrastFailure` (4,5:1) dan token merek (`brandTokens`) tidak null; untuk `krem`, `gray-50` dan `gray-100` krem berkontras >= 4,5:1 dengan `gray-600`, `gray-700`, `gray-900` (nilai skala slate bawaan), dan `gray-900` pada `gray-100`.
- **AC-4 Keluaran CSS:** tema ber-`palette: hijau` tanpa pengaturan admin menulis `--color-primary-*`, `--color-accent-*` (bila aksen palet ada), dan token merek; `surface: krem` menulis tepat dua deklarasi gray; `font: lora` menulis `--font-heading` dan `font_aliases` Lora; hanya karakter dari peta (regex hex/nama token) yang muncul di `css`.
- **AC-5 Prioritas admin:** `theme.primary` admin terisi mengalahkan primer palet; `theme.accent` dan `theme.font` sama; admin kosong mengikuti tema; admin primer + aksen palet yang gagal kontras membuang skala yang gagal (jalur lama), tanpa exception.
- **AC-6 Fallback:** tema hilang/rusak/`min_core` terlalu tinggi, atau font tema yang aliasnya tak ada di manifest font build, memberi keluaran seperti tanpa tema (portal 200, tanpa exception).
- **AC-7 Portal:** halaman beranda dengan tema uji bertipografi/berwarna memuat `<style data-portal-theme>` bertoken tema, tetap ber-`Cache-Control` public + ETag, tanpa `Set-Cookie`; jumlah query tidak naik dibanding tanpa kunci baru.
- **AC-8 Ketat:** test statis: `ThemePalette` hanya berisi literal (tanpa interpolasi/variabel di konstanta), dan CSS `GetPortalTheme` tak pernah memuat string dari `theme.json` selain lewat peta (uji dengan tema uji bernilai jebakan yang ditolak validator).
- **AC-9 Dokumen:** `docs/rilis.md` (kunci baru, enum, prioritas admin, `min_core` 1.1.0, catatan versi), `docs/design-guide.md`, `lang/id` diperbarui; catatan di Situs > Tampilan bahwa warna/font bawaan mengikuti tema aktif.

## Rencana test
`tests/Feature/Actions/Konten/PortalThemeSchemaTest` (AC-1, 2), `ThemePaletteTest` baru (AC-3, 8), `GetPortalThemeTest` (AC-4, 5, 6), `Http/Portal/PortalThemeRenderTest` (AC-7), `ThemePageTest` (catatan AC-9). Memakai `themeBuild()`, `themeSet()`, `themeOfficialManifest()` di `tests/Pest.php`.

## Urutan implementasi
1. `ThemePalette`, `ValidatePortalTheme` (kunci + `CORE_VERSION`), `lang/id` -> hijau AC-1, 2, 3.
2. `GetPortalTheme` terhubung ke tema aktif -> hijau AC-4 sampai 8.
3. Dokumen dan catatan di halaman Tampilan; Pint; suite penuh sekali di akhir.

## Risiko
- `ResolveActiveTheme` membaca berkas tema pada setiap pemanggilan `GetPortalTheme`: sudah dimemoisasi per request; pastikan `GetPortalTheme` tidak dipanggil di luar request publik berulang (halaman Tampilan di admin boleh).
- `krem` hanya mewarnai permukaan `gray-50/100`; latar `body`/`tone: light` tetap putih (batasan sadar, bukan bug).
- F103 (#138) juga menyentuh `ValidatePortalTheme`; rebase kecil saat salah satunya masuk `dev` lebih dulu.
