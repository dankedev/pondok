# F091 Pencarian global: kategori Aksi

Issue: #109 · Jalur: cepat · Modul: inti · Lanjutan F090

## Tujuan
Ctrl+K menampilkan "Tambah <model>" untuk resource yang boleh dibuat pengguna, tanpa daftar aksi yang dikurasi (aplikasi open source).

## Perubahan
- `NavigationAwareGlobalSearchProvider::matchingActions()`: iterasi `Filament::getResources()`, ambil yang `hasPage('create')` dan `canCreate()`.
- `lang/id/pondok.php`: `pondok.navigation_search.actions` dan `.create`.

## Kriteria penerimaan
- Pencarian "tambah" memuat aksi untuk resource yang boleh dibuat.
- Peran tanpa izin atau modul mati tidak melihat aksinya.
- Kata kunci satu huruf tidak memuat kategori Aksi.
- Resource tanpa halaman create (mis. kelola lewat modal) tidak ikut; di luar cakupan (YAGNI).
