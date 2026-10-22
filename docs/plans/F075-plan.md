# F075 Perbaikan tampilan: menu portal 1024px dan teks rincian biaya PSB

Issue: #65
Jalur: cepat (Blade + teks `lang/id`, tanpa migration, uang, atau hak akses)

## Tujuan

Memperbaiki dua bug kecil temuan cek tampilan 22 Okt 2026.

1. Nav penuh portal tampil mulai `lg` (1024px), padahal bisa memuat hingga 8 item + Masuk + tombol header. Di 1024px label "Tentang Kami", "Info PSB", dan "Daftar PSB" terlipat dua baris.
2. Info PSB menampilkan "Untuk gelombang Gelombang I tahun ajaran …" karena nama gelombang biasanya sudah memuat kata "Gelombang".

## File yang diubah

- `resources/views/layouts/public.blade.php`: nav penuh `xl:flex`, menu lipat `xl:hidden`.
- `resources/views/portal/partials/nav-links.blade.php`: tautan desktop `whitespace-nowrap`.
- `lang/id/pondok.php`: `portal.psb_info.fees_note` menjadi "Berlaku untuk :wave, tahun ajaran :year." (nama gelombang bebas diisi admin).

## Kriteria penerimaan

1. Di 1024px header memakai menu lipat; semua item nav dan tombol header ada di dalamnya.
2. Di 1280px ke atas nav penuh tampil satu baris tanpa label terlipat.
3. Rincian biaya Info PSB menampilkan "Berlaku untuk Gelombang I, tahun ajaran 2027/2028."
4. Tanpa overflow horizontal di 360px, 1024px, dan desktop.

Tidak ada test baru: perubahan murni tampilan dan teks.
