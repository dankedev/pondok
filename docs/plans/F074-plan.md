# F074 Kunci blok konten di Builder Beranda saat modul Konten mati

Issue: #58
Jalur: cepat (form admin dan test; tanpa migration, uang, atau hak akses).
Bergantung: F045 (beranda berbasis blok). Tindak lanjut F066 (#35).

## Tujuan

Saat modul Konten mati, portal sudah tidak merender blok `video` dan `feed` (`GetPortalHomeData`), tetapi
pemilih blok di Situs → Beranda masih menawarkannya. Admin tidak boleh bisa menambah blok yang tidak akan tampil,
dan blok yang sudah tersimpan tidak boleh hilang dari form.

## Temuan Filament (v5)

- `Builder::getBlocks()` memakai `Schema::getComponents()` yang membuang komponen tersembunyi. Blok yang
  definisinya `->hidden()` tidak masuk `getDefaultChildSchemas()`, jadi item tersimpan bertipe itu tidak
  dirender di form. `Block::hidden()` tidak dipakai.
- `Builder::getBlockPickerBlocks()` menyaring blok dengan `Block::getMaxItems()` (`jumlah < maxItems`).
  `maxItems` blok hanya dipakai di situ, jadi `maxItems(0)` menyembunyikan blok dari pemilih tanpa memengaruhi
  item yang sudah ada.

## Perubahan

1. `app/Filament/Pages/Site/Home.php`: blok `video` dan `feed` diberi
   `->maxItems(fn (): ?int => Setting::moduleEnabled('konten') ? null : 0)` lewat satu helper kecil.
   Docblock kelas diperbarui.
2. `tests/Feature/Filament/Pages/HomeBuilderVerifyTest.php`: test perilaku di bawah.

## Kriteria penerimaan

1. Modul Konten mati: `getBlockPickerBlocks()` tidak memuat `video` dan `feed`; blok lain tetap ada.
2. Modul Konten mati: blok video/feed tersimpan tetap ada di state form, field-nya tampil, dan tetap
   tersimpan setelah `save`.
3. Modul Konten hidup: pemilih memuat `video` dan `feed`.
4. Pint lulus; form dicek di 360px dan desktop.
