# F073 Rapikan sisa catatan kecil blok beranda

Issue: #26
Jalur: cepat (tampilan, form admin, test; tanpa migration, uang, atau hak akses).
Bergantung: F045 (beranda berbasis blok), F072 (preset template).

## Tujuan

Menutup tiga catatan minor dari review dan VERIFY F045.

## Perubahan

1. `resources/views/components/portal/adaptive-grid.blade.php` + `resources/css/app.css`: untuk 3 dan 4 kolom,
   CSS grid diganti kelas komponen `.portal-adaptive-grid` (flex-wrap, `justify-center`, `gap-6`, lebar anak
   = satu kolom) dan utilitas `adaptive-cols-<n>` (`sm:adaptive-cols-2 lg:adaptive-cols-3|4`). CSS grid tidak
   bisa merata-tengahkan baris sisa, dan nilai arbitrer dilarang di view portal (§6, `PortalVisualIdentityTest`).
   Dipakai blok Keunggulan dan Konten terbaru preset default. 1–2 kolom tidak berubah.
2. `resources/views/themes/centered/blocks/features.blade.php`: daftar dua kolom dengan jumlah ganjil,
   butir terakhir ditaruh di tengah (`lg:last:odd:col-span-2 lg:last:odd:mx-auto lg:last:odd:w-1/2`).
3. `app/Filament/Pages/Site/Home.php`: field `album_id` hero disembunyikan bila
   `modules.konten.enabled` mati (`Setting::moduleEnabled('konten')`), tetap ikut disimpan
   (`dehydratedWhenHidden`) agar rujukan album tidak hilang saat modul dinyalakan lagi.
   Unggah gambar hero tidak terikat modul Konten (berkas langsung di disk `public`), jadi tetap tampil.
4. `tests/Feature/Filament/Pages/HomeBuilderVerifyTest.php`: test path gambar palsu juga memastikan
   galat ada di field `data.home_blocks.blok-a.data.image` dengan pesan `image_invalid`.
5. `app/Filament/Pages/SettingsFormPage.php`: assert yang lebih ketat menemukan galat jatuh ke
   `data.home_blocks.0.data.image` (bukan key blok), karena `getState()` Builder sudah berupa daftar berindeks,
   sehingga pesan tidak tampil di field blok yang tepat di UI. Pemetaan galat memakai `getRawState()`.

## Kriteria penerimaan

- AC-1 Keunggulan default dengan 5 item: `lg` 3 kartu + 2 kartu rata tengah, lebar kartu sama; 360px satu kolom.
- AC-2 Keunggulan Terpusat dengan 3 item: butir ketiga di tengah pada `lg`, 360px tetap daftar satu kolom.
- AC-3 Modul Konten mati: form Beranda tidak menampilkan pilihan album hero; modul hidup: tampil.
- AC-4 Simpan saat modul Konten mati tidak menghapus `album_id` yang sudah tersimpan.
- AC-5 Path gambar palsu ditolak di field blok yang tepat dengan pesan yang tepat; settings tidak berubah.
- AC-6 Tidak ada scroll horizontal di 360px; 1024px dan desktop dicek.

## Test

`HomeBuilderVerifyTest` (AC-5), test baru di file yang sama untuk AC-3/AC-4. `HomeBlocksTest` AC-11
membaca kolom dari `lg:adaptive-cols-<n>` juga. Tampilan grid (AC-1, AC-2) dicek visual.

## Di luar cakupan

Blok Video dan Konten terbaru tetap bisa dipilih saat modul Konten mati (tidak dirender di portal).
Bila perlu disembunyikan, jadi issue baru.
