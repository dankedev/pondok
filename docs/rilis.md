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
