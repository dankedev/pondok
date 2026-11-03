# F104 konten-tema-warna-font — Tema portal menentukan warna dan tipografi

Issue: #140
Status rencana: APPROVED (revisi 2; Review #1: 0 blocker, 3 major + 6 minor terjawab, satu putaran)
PR ditujukan ke `dev` (cabang `feat/f104-warna-tipografi-tema`), memakai `Refs #140`, bukan `Closes`.
Jalur: penuh (skema dan validator tema).

## Ringkasan & tujuan
Skema tema F102 hanya mengatur struktur. Tema resmi belum bisa tampil beda warna dan huruf (mis. Amanah: hijau-krem dengan judul serif). Perluasan ini menambah **tiga kunci puncak opsional berupa enum** pada `theme.json`: `palette`, `surface`, `font`. Tetap data saja: nilai dipetakan server ke token yang sudah ada, tanpa hex, nama font, CSS, atau berkas dari tema.

## Temuan penting (mengubah usulan awal)
Situs sudah punya kontrol warna dan font dari admin (F070, Situs > Tampilan): `theme.primary`, `theme.accent`, `theme.font` (`jakarta|lora|nunito`), `theme.radius`, diolah `GetPortalTheme` + `BuildColorScale` (skala 50-950, guard kontras 4,5:1) menjadi `<style data-portal-theme>:root{...}`. Maka F104 **tidak** membuat mesin warna/font baru; tema hanya menyuplai **nilai bawaan** ke mesin yang sama:
- **Font:** `font` memakai kunci `GetPortalTheme::FONTS` yang ada (`jakarta`, `lora`, `nunito`; semuanya sudah ada di build, `noto-naskh-arabic` tetap font Arab). Tidak ada pasangan font baru.
- **Warna:** `palette` = nama yang dipetakan ke satu hex primer yang diolah `BuildColorScale` (aksen tetap emas bawaan). `surface` = nama yang menimpa `--color-gray-50` dan `--color-gray-100` (permukaan band `tone: muted`/`auto`, kartu) dengan tint hangat; `--color-white` tidak disentuh (teks putih tetap putih).
- **Prioritas:** nilai admin yang terisi (`theme.primary`, `theme.accent`, `theme.font`) menang atas tema; nilai admin kosong (null = bawaan) mengikuti tema. Konsekuensi (diterima, KISS): admin tidak bisa "memilih Jakarta secara eksplisit" di atas tema berfont Lora, karena bawaan disimpan null. `theme.radius` tidak dipengaruhi tema.

## Keputusan versi skema
**Tambahan kompatibel, `schema_version` tetap 1, `ValidatePortalTheme::CORE_VERSION` naik `1.0.0` -> `1.1.0`.** Alasan: kebijakan `docs/rilis.md` (versi naik hanya untuk perubahan merusak; tambahan = `min_core`); semua kunci baru opsional sehingga tema v1 lama (termasuk Amanah/Lapang 1.0.0 dan tanda tangannya, yang menandatangani byte berkas) tetap valid tanpa diubah dan tanpa `MigrateThemeSchema` (tetap kerangka kosong). Tidak ada aturan khusus `min_core` untuk kunci baru (YAGNI): inti lama (1.0.0) menolaknya lewat kunci tak dikenal dan jatuh ke chrome inti; tema yang memakainya sebaiknya menulis `min_core` 1.1.0 (panduan di `docs/rilis.md`). Bila F103 (#138) juga menaikkan `CORE_VERSION`, nomor akhir disepakati saat rebase (mis. 1.2.0).

## Ruang lingkup
- Termasuk: tiga kunci enum + validator, `ThemePalette` (peta nama -> token), integrasi di `GetPortalTheme`, `CORE_VERSION` 1.1.0, test (termasuk kontras per palet), `docs/rilis.md`, `docs/design-guide.md` (§2 dan §6 satu paragraf), `lang/id`.
- Tidak termasuk: hex/nama font/URL font bebas dari tema, pengubahan `bg-white` body, `--color-white`, warna status (success/warning/danger/info), radius dari tema, font baru di build, perubahan UI Situs > Tampilan selain satu catatan "warna/font bawaan mengikuti tema", penerbitan ulang Amanah/Lapang (repo `pondok-themes-work`, di luar repo ini), semua hal F103.

## Skema tambahan
Kunci puncak opsional (set kunci tertutup tetap): 
- `palette`: `navy` (bawaan inti; tanpa deklarasi) | `hijau`. Palet lain ditambah saat ada tema yang memakainya.
- `surface`: `putih` (bawaan; tanpa deklarasi) | `krem`.
- `font`: `jakarta` | `lora` | `nunito` (kunci `GetPortalTheme::FONTS`; nilai lain ditolak, satu sumber kebenaran lewat `array_keys`).

Peta di `app/Actions/Konten/ThemePalette.php` (konstanta, hex literal, tanpa interpolasi):
- `PALETTES`: `hijau` => satu hex primer `#...`; hex final dipilih green-coder sampai lolos AC-3.
- `SURFACES`: `krem` => `['--color-gray-50' => '#...', '--color-gray-100' => '#...']`.

Penerapan di `GetPortalTheme::handle()`: tema aktif dibaca dari `ResolveActiveTheme` (memoisasi per request, tanpa query baru); `primary` efektif = `theme.primary` admin ?? primer palet tema; `accent` hanya dari admin; `font` efektif = `theme.font` admin ?? `font` tema; deklarasi `surface` ditambahkan ke `:root{}`. Setelahnya jalur lama tidak berubah (guard kontras, `brandTokens`, cek `fontsAvailable`, `<style data-portal-theme>`). Tema nonaktif/rusak/null = perilaku sekarang persis. Semua bawaan = `css` null seperti sekarang.

## Kriteria penerimaan
- **AC-1 Kompatibilitas:** untuk tema uji v1 tanpa kunci baru (`min_core` 1.0.0) dan tema resmi `default`/`centered`/`majalah`/`mushaf` (tidak diubah), keluaran `GetPortalTheme::handle()` sama persis dengan keadaan tanpa tema, lintas kombinasi pengaturan admin (kosong, primer, aksen, font).
- **AC-2 Validator:** `palette`, `surface`, `font` menerima hanya nilai enum (string, pembanding `in_array` strict); nilai lain (angka, `true`, `0`, hex, array, kosong, nama font bebas, `url(...)`, huruf besar) ditolak dengan pesan Indonesia; `min_core` 1.1.0 diterima dan 1.2.0 ditolak (`CORE_VERSION` 1.1.0).
- **AC-3 Kontras:** skala `BuildColorScale` palet `hijau` + aksen bawaan lolos `contrastFailure` (4,5:1) dan `brandTokens` tidak null. Untuk `krem`: `gray-50` dan `gray-100` krem berkontras >= 4,5:1 dengan `gray-500`, `gray-600`, `gray-700`, `gray-900` (skala slate bawaan), serta dengan `brand_text` dan `primary-700` palet `hijau`. Pengecualian terukur: `gray-500` di atas `gray-100` krem cukup tidak lebih buruk dari slate-100 bawaan (4,35:1), karena 4,5:1 hanya tercapai oleh warna yang nyaris putih.
- **AC-4 Keluaran CSS:** tema `palette: hijau` tanpa pengaturan admin menulis `--color-primary-*` dan token merek; `surface: krem` menulis tepat dua deklarasi gray; `font: lora` menulis `--font-heading` dan `font_aliases` Lora; setiap nilai di `PALETTES`/`SURFACES` cocok `/^#[0-9a-f]{6}\z/` dan nama token `/^--color-[a-z0-9-]+\z/`.
- **AC-5 Prioritas admin:** `theme.primary`, `theme.accent`, `theme.font` admin yang terisi mengalahkan tema; kosong mengikuti tema; aksen admin yang gagal kontras terhadap primer palet dibuang oleh jalur lama tanpa exception (batasan sadar: `UpdatePortalTheme::ensureReadable` dan pratinjau Tampilan hanya mengenal nilai admin, jadi bisa berbeda dari portal; diterima).
- **AC-6 Fallback:** tema hilang/rusak/`min_core` terlalu tinggi memberi keluaran seperti tanpa tema (portal 200); font (tema atau admin) yang aliasnya tak ada di manifest font build jatuh ke bawaan Jakarta tanpa exception.
- **AC-7 Portal:** beranda dengan tema uji berwarna/bertipografi memuat `<style data-portal-theme>` bertoken tema, tetap `Cache-Control` public + ETag, tanpa `Set-Cookie`; jumlah query tidak naik dibanding tema v1 tanpa kunci baru.
- **AC-8 Dokumen dan UI:** `docs/rilis.md` (kunci, enum, prioritas admin, `min_core`), `docs/design-guide.md`, `lang/id` diperbarui; catatan di Situs > Tampilan bahwa warna/font bawaan mengikuti tema aktif.

## Rencana test
`tests/Feature/Actions/Konten/PortalThemeSchemaTest` (AC-1, 2), `ThemePaletteTest` baru (AC-3, 4), `GetPortalThemeTest` (AC-4, 5, 6), `Http/Portal/PortalThemeRenderTest` (AC-7), `ThemePageTest` (catatan AC-8). Memakai `themeBuild()`, `themeSet()`, `themeOfficialManifest()` di `tests/Pest.php`.

## Urutan implementasi
1. `ThemePalette`, `ValidatePortalTheme` (kunci + `CORE_VERSION`), `lang/id` -> hijau AC-1, 2, 3.
2. `GetPortalTheme` terhubung ke tema aktif -> hijau AC-4 sampai 7.
3. Dokumen dan catatan di halaman Tampilan; Pint; suite penuh sekali di akhir.

## Risiko
- `ResolveActiveTheme` membaca berkas tema pada setiap pemanggilan `GetPortalTheme`: sudah dimemoisasi per request; pastikan `GetPortalTheme` tidak dipanggil di luar request publik berulang (halaman Tampilan di admin boleh).
- `krem` hanya mewarnai permukaan `gray-50/100`; latar `body`/`tone: light` tetap putih (batasan sadar, bukan bug).
- Opsi font bawaan di Tampilan tersimpan null dan tetap mengikuti tema (admin tidak bisa memaksa Jakarta di atas tema berfont lain); diterima, catatan UI menjelaskannya.
- F103 (#138) juga menyentuh `ValidatePortalTheme`; rebase kecil saat salah satunya masuk `dev` lebih dulu.

## Review #1 (plan-reviewer): CHANGES_REQUESTED, 0 blocker, 3 major, 6 minor
Major: aksen palet tanpa pemakai dan palet `biru` (dibuang), aksen admin vs primer palet (AC-5 + batasan), kontras `krem` kurang lengkap (AC-3 dilengkapi). Minor: aturan `min_core` kunci baru dibuang, AC-1/8 diperjelas, catatan UI font bawaan dan nomor versi F103 masuk Risiko. Semua diterapkan di revisi 2.
