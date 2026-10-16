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
