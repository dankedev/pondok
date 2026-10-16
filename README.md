# Pondok

Sistem informasi pesantren dan sekolah berasrama: data santri, akademik, asrama, penerimaan santri baru (PSB), keuangan, dokumen dan surat, prestasi, serta portal publik. Dibangun dengan Laravel dan Filament, dan dirancang berjalan di shared hosting cPanel (PHP 8.3 dan MySQL) tanpa staf IT khusus.

## Dokumentasi

- [Panduan instalasi di cPanel](docs/instalasi.md) untuk operator sekolah.
- [Panduan rilis](docs/rilis.md) untuk pengelola yang membuat paket zip.
- [Dokumen kebutuhan produk (PRD)](docs/prd.md).

## Pengembangan

Kebutuhan: PHP 8.3+, Composer, Node.js dengan pnpm, dan MySQL atau SQLite.

```
composer install
pnpm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
pnpm run build
php artisan test --compact
```

## Lisensi

MIT.
