# F047 — Filter berita: sembunyikan chip kosong

Jalur: cepat. Audit C4 (`docs/audits/ui-ux-portal-konten.md` §2.4, §5.7).

## Tujuan
Di `/berita`, chip jenis dan jenjang tanpa berita terbit disembunyikan, baris jenjang hanya tampil bila ≥2 jenjang punya berita, dan baris tanpa chip tidak dirender. Kategori sudah hanya menampilkan yang berisi berita terbit.

## File diubah
- `app/Actions/Konten/ListPublicPosts.php`: hitung jenis dan jenjang terpakai dengan satu query agregat (distinct) per dimensi; kembalikan `types` dan `units` yang sudah disaring.
- `resources/views/portal/posts/index.blade.php`: pakai `$types`, sembunyikan baris jenis bila kosong.
- `tests/Feature/Http/Portal/PostFilterVisibilityTest.php`: test baru.

## Di luar cakupan (YAGNI)
Penggabungan taksonomi/hapus `type` (S6), berita terkait, tautan kategori di detail. Tanpa migration.

## Kriteria penerimaan
1. Chip jenis tanpa berita terbit tidak tampil; baris jenis hilang bila tidak ada berita terbit.
2. Chip jenjang tanpa berita terbit tidak tampil.
3. Baris jenjang hanya tampil bila ≥2 jenjang punya berita terbit.
4. Filter aktif tetap tampil (agar bisa dilepas) dan URL filter tak dikenal tetap 404.
5. Dicek di 360px dan 1024px.
