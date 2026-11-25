# F115 Tautan Panduan pondok.org di panel admin

Issue: #168 · Jalur cepat

## Tujuan
Admin bisa membuka panduan pondok.org yang relevan dari halaman yang sedang dibuka (mis. Peran → "Membuat peran dan memilih izin").

## Perubahan
- `config/pondok.php`: `guides.base_url` dan `guides.pages` (kelas halaman → slug `/panduan/<slug>/`).
- `AdminPanelProvider`: render hook `PAGE_HEADER_ACTIONS_AFTER` per halaman di peta, dan item menu pengguna "Semua panduan".
- `resources/views/filament/guide-link.blade.php`, teks `pondok.guides.*` di `lang/id`.

## Kriteria penerimaan
1. Halaman Peran, Pengguna, Modul, dan Prestasi menampilkan tautan "Panduan" ke slug yang sesuai.
2. Tautan terbuka di tab baru.
3. Menu pengguna memuat tautan indeks `https://pondok.org/panduan/`.
4. Hanya slug yang sudah ada; tanpa anchor.

## Di luar lingkup
Halaman PSB, Keuangan, Akademik (panduan belum ditulis); migrasi checklist Setup awal dari `/docs/` ke `/panduan/`.
