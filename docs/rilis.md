# Panduan rilis Pondok (untuk pengelola)

Panduan membuat paket rilis zip yang diunggah sekolah ke hosting cPanel. Panduan pemasangan untuk sekolah ada di [docs/instalasi.md](instalasi.md).

## Prasyarat mesin build

- PHP 8.3 atau lebih baru dengan ekstensi `zip` (CLI).
- Composer 2.
- Node.js dan pnpm (hanya di mesin build; server sekolah tidak butuh Node).
- git, dengan repo dalam keadaan bersih (semua perubahan sudah di-commit).

`composer.json` mengunci platform PHP 8.3, jadi dependency yang terpasang tetap cocok untuk server PHP 8.3 walau mesin build memakai versi PHP lebih baru.

## Membuat paket

```
php artisan pondok:release --release-version=1.2.0
```

Langkah yang dijalankan:

1. Menolak berjalan bila `APP_ENV=production`, atau bila ada perubahan yang belum di-commit (kecuali `--allow-dirty`; hanya commit `HEAD` yang dipaketkan).
2. `git archive HEAD` ke folder staging sementara.
3. `composer install --no-dev --optimize-autoloader` di staging (skrip Composer menerbitkan aset Filament).
4. `pnpm install --frozen-lockfile --ignore-workspace` lalu `pnpm run build` (aset Vite ke `public/build`).
5. Menulis berkas `VERSION`.
6. Memeriksa kelengkapan paket. Paket ditolak bila tidak ada `public/build/manifest.json`, `vendor/autoload.php`, aset Filament, `.htaccess` root, `.env.production.example`, atau `docs/instalasi.md`, atau bila ada paket pengembangan (`vendor/pestphp/`, dsb.) maupun berkas `.env` rahasia.
7. Menulis `pondok-<versi>.zip` (berakar folder `pondok/`) dan `pondok-<versi>.zip.sha256` ke `storage/app/releases`.

Opsi:

| Opsi | Fungsi |
|---|---|
| `--release-version=` | Versi paket. Bawaan: hasil `git describe --tags --always`. |
| `--output=` | Folder hasil. Bawaan `storage/app/releases`. |
| `--allow-dirty` | Lanjut walau ada perubahan belum di-commit (tidak ikut paket). |
| `--keep-staging` | Simpan folder staging untuk diperiksa. |

## Isi paket

Ikut: kode aplikasi, `vendor/` tanpa paket dev, `public/build`, aset Filament (`public/css|js|fonts/filament`), `.env.example`, `.env.production.example`, `.htaccess` root penolak akses, kerangka folder `storage` dan `bootstrap/cache`, `docs/` (kecuali `docs/plans` dan `docs/handoffs`), dan `VERSION`.

Tidak ikut: `.env` dan varian rahasianya, `.git`, `node_modules`, `tests`, berkas kerja agent (`.claude`, `CLAUDE.md`, `AGENTS.md`, `boost.json`, `feature_list.json`), log, cache, sesi, view terkompilasi, unggahan, `database/database.sqlite`, dan `public/storage`.

Periksa isi zip bila perlu:

```
unzip -l storage/app/releases/pondok-1.2.0.zip | less
```

## Tema portal (skema v1)

Tema portal adalah satu berkas `resources/themes/<slug>/theme.json` (data saja, tanpa kode, tanpa aset; `slug` harus sama dengan nama folder). Empat tema resmi: `default`, `centered`, `majalah`, `mushaf`. Tema dipilih di Situs > Tampilan; tema yang gagal validasi tampil nonaktif dan portal memakai chrome inti.

Isi manifes v1: `schema_version` (1), `version`, `slug`, `name`, `description`, `min_core`, serta `header`, `footer`, dan `home.layout` (daftar node `{component, variant?, style?, children?}` berurutan bebas: komponen boleh di posisi mana pun, berulang, dan bersarang di `container`/`columns`) yang disusun dari 15 komponen tetap dengan nilai gaya dari enum (padding, kolom, tone, dsb.). Node `blocks` menampilkan semua blok beranda dari admin sesuai urutannya (dipakai `default` dan `centered`); node isi (hero, feed_list, dst.) menampilkan blok aktif pertama bertipe itu. Editor visual dan susunan per situs di database adalah pekerjaan lanjutan dengan skema yang sama. Batas: kedalaman pohon 6, 150 node, 64 KB. Nilai di luar enum, kunci tak dikenal, dan `assets` ditolak.

### Warna dan huruf tema (tambahan v1, inti 1.1.0)

Tiga kunci puncak opsional berupa enum; tema hanya menyebut nama, tanpa hex, nama font, CSS, atau berkas:

| Kunci | Nilai | Efek |
|---|---|---|
| `palette` | `navy` (bawaan, tanpa deklarasi), `hijau` | warna utama (`primary-50..950` dan token merek) dari satu hex primer di `ThemePalette::PALETTES`; aksen tetap emas bawaan |
| `surface` | `putih` (bawaan), `krem` | menimpa `--color-gray-50` dan `--color-gray-100` (band `tone: muted`/`auto`, kartu); `--color-white` tidak disentuh |
| `font` | `jakarta`, `lora`, `nunito` | pasangan font bawaan inti (`GetPortalTheme::FONTS`); font Arab tetap Noto Naskh |

Prioritas: nilai admin di Situs > Tampilan yang terisi (`theme.primary`, `theme.accent`, `theme.font`) menang atas tema; nilai kosong mengikuti tema; aksen hanya dari admin; radius tidak dipengaruhi tema. Guard kontras 4,5:1 dan cek manifest font tetap berlaku, jadi warna/font yang tak lolos jatuh ke bawaan. Nilai di luar enum membuat tema ditolak.

Kebijakan versi: kunci ini tambahan kompatibel, `schema_version` tetap 1 dan `CORE_VERSION` naik ke `1.1.0`. Tema yang memakainya sebaiknya menulis `min_core` `1.1.0`; inti 1.0.0 menolaknya (kunci tak dikenal) dan memakai chrome inti. Tema v1 lama tetap valid tanpa diubah.

### Panduan `home.layout` (skema v1)

Node: `{component, variant?, style?, children?}`. Komponen tata letak: `container` (susun vertikal; di header berderet), `columns` (anak = sel, 2 sampai 4 anak), `divider` (hiasan emas, satu bentuk). Komponen isi mengikat blok beranda aktif pertama bertipe sama: `hero`, `text_image`, `feature_grid` (blok keunggulan), `quote`, `cta`, `video`, `feed_list` (blok konten terbaru). `blocks` adalah preset lama: wajib satu-satunya node, tanpa `style`, varian `default` atau `centered`.

Varian (yang pertama = bawaan bila `variant` dihilangkan):

| Komponen | Varian |
|---|---|
| `hero` | `center`, `split` |
| `feed_list` | `cards`, `list`, `magazine` |
| `quote` | `plain`, `framed` |
| `cta` | `band`, `card` |
| `feature_grid` | `cards`, `numbered` |
| `blocks` | `default`, `centered` |

Aturan perilaku:

- **Node kosong.** Node tampil bila ada anak isi yang tampil. Tanpa blok bertipe itu (atau blok disembunyikan admin), node beserta pembungkusnya tidak dirender. Sel `columns` kosong dibuang dan jumlah kolom = `min(style.columns, sel tampil)`. `divider` hanya tampil bila bersebelahan dengan konten yang tampil.
- **Judul.** Hero pertama yang tampil memakai `h1`, berikutnya `h2`. Bila node bertajuk (text_image, feature_grid, cta, video, feed_list) tampil sebelum hero, hero tetap `h2` dan `h1` sr-only nama lembaga ditaruh di awal `<main>`. Tanpa hero, tampil `h1` sr-only (atau pita nama lembaga pada preset `blocks`).
- **`tone: auto`.** Selang-seling `bg-gray-50` dan `bg-white` hanya untuk node level atas `home.layout`, dihitung menurut urutan tampil (node kosong dan `divider` tidak menggeser). Tidak sah di node bersarang, header, atau footer.
- **Gaya.** Hanya enum `ThemeStyleMap` (tone, padding, gap, align, width, radius, shadow, border, columns); responsif `{base, md, lg}` hanya untuk `columns` dan `padding`. `style.columns` hanya pada `columns` (nilai 1 sampai 4, tidak melebihi jumlah anak). Node bersarang tidak mendapat padding atau gutter bawaan.
- **Teks.** Tema hanya membawa struktur. Seluruh teks tampil berasal dari data sekolah (blok beranda, navigasi, footer); `name` dan `description` tema bukan teks portal.

Pekerjaan lanjutan (belum ada, diusulkan sebagai issue terpisah menunggu persetujuan): editor visual susunan di Filament dengan susunan per situs di database (memakai skema yang sama), dan pemilihan blok ke-n (`nth`) agar sekolah dengan beberapa blok sejenis bisa menempatkan blok kedua dst. Saat ini node isi selalu memakai blok aktif pertama bertipe itu.

Kebijakan versi:

- `schema_version` naik hanya untuk perubahan yang merusak tema lama. Inti mendukung versi N dan N-1 (N-1 dimigrasikan dalam memori oleh `MigrateThemeSchema`, berkas tidak diubah).
- Tambahan yang kompatibel (komponen atau varian baru) cukup menaikkan `min_core`: tema dengan `min_core` di atas versi inti dianggap tidak valid dan portal memakai chrome inti.
- Versi di luar N dan N-1 diperlakukan sebagai tidak didukung (fallback inti, peringatan log sekali per slug).

Saat rilis, jalankan `npm run build` (atau `pnpm run build`, sudah ada di `pondok:release`) sebelum memaketkan: kelas Tailwind peta gaya (`ThemeStyleMap`, kelas ditulis utuh sebagai literal) dan utilitas `portal-gutter` hanya ada di CSS bila dibuild. Test tidak memeriksa CSS hasil build, jadi periksa sekilas satu tema di portal setelah build.

## Memeriksa checksum

Sekolah dapat mencocokkan berkas yang diunduh:

```
shasum -a 256 -c pondok-1.2.0.zip.sha256
```

## Menandai versi

Setelah paket diuji (instalasi bersih dan pembaruan dari versi sebelumnya), tandai commit-nya:

```
git tag -a v1.2.0 -m "Pondok 1.2.0"
git push origin v1.2.0
```

Tanpa `--release-version`, `pondok:release` memakai tag ini sebagai versi.
