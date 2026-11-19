# F114 Komponen beranda berulang memakai blok admin berikutnya

Issue: #166. Jalur: cepat (tanpa migration, uang, atau hak akses).

## Tujuan
Tema yang memakai komponen sama lebih dari sekali (mis. dua Kutipan) tidak lagi menampilkan isi kembar bila admin punya beberapa blok bertipe itu di Beranda.

## Perubahan
- `ResolveHomeLayout`: blok dikelompokkan per tipe; node ke-n bertipe X memakai blok aktif ke-n bertipe X, kembali ke blok pertama bila tidak ada (kontrak F102 tetap untuk blok tunggal).
- `docs/rilis.md`: kalimat pengikatan blok diperbarui.

## Kriteria penerimaan
- AC-1: node ke-n memakai blok ke-n, termasuk node bersarang di container.
- AC-2: tanpa pasangan, node memakai blok pertama.
- AC-3: node tunggal tetap memakai blok pertama walau ada blok lain.
- AC-4: preset `blocks` dan tema resmi tidak berubah perilakunya.
