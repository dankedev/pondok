# Data demo Ma'had Al-Khair Belitang

Data contoh untuk mencoba panel admin dan portal publik. Nama lembaga, alamat (Mojosari, Belitang, OKU Timur), tagline, program, dan nominal biaya mengikuti brosur PSB 2027/2028. Semua nama orang, nomor telepon, email, nomor KK, dan isi berita **fiktif**. Nomor telepon dan rekening brosur tidak dipakai.

## Biaya demo

Tarif berlaku untuk MTW/SMP dan TSN/SMA:

| Kode | Biaya | Nominal |
|---|---|---|
| `SPP` | SPP bulanan (termasuk makan 3x sehari, laundry, operasional) | Rp1.600.000 |
| `FORMULIR` | Biaya pendaftaran | Rp300.000 |
| `UANG_MASUK` | Uang masuk | Rp10.000.000 |
| `DAFTAR_ULANG` | Daftar ulang tahunan: nominal **contoh** Rp1.500.000, brosur tidak menyebut angka; sesuaikan dengan keputusan pondok | Rp1.500.000 (contoh) |

- SPP TSN/SMA angkatan kelas 12 memakai tarif lama terkunci Rp1.500.000 (contoh tarif angkatan).
- Tagihan tahunan tidak dibuat: belum ada generator tagihan tahunan, hanya jenis biaya dan tarifnya.
- Subsidi dan beasiswa terbatas untuk 3 penerima: Subsidi Yatim (bayar SPP Rp800.000), Beasiswa Tahfidz (50% SPP), dan Subsidi Uang Masuk (potongan Rp5.000.000 lewat jalur PSB `SUBSIDI`). Satu santri lain mendapat keringanan SPP Rp300.000 dari wawancara biaya.

## Menjalankan

```bash
php artisan migrate:fresh --force
php artisan db:seed --class=DemoSeeder
```

- Jalankan `php artisan migrate:fresh` lebih dulu. Revisi data demo ini (khusus putra, tanpa SD, tarif brosur) tidak memigrasikan data demo versi lama.
- Seeder memanggil `InstallSeeder` lebih dulu, jadi cukup dijalankan di database yang sudah dimigrasi.
- Seeder **menolak berjalan** bila `APP_ENV=production`.
- Seeder tidak dipanggil `DatabaseSeeder`, `InstallSeeder`, `pondok:install`, maupun `pondok:upgrade`.
- Aman dijalankan ulang: data yang sudah ada dilewati, tidak ada baris, berkas, atau pembayaran ganda.
- Tanggal dihitung dari hari ini (Asia/Jakarta): tahun ajaran lalu, aktif, dan tahun depan, tagihan empat bulan terakhir, serta pembayaran yang tidak pernah melewati hari ini.
- Bila seeding terputus di tengah, ulangi dari `php artisan migrate:fresh` agar alur PSB dan pembayaran lengkap.
- Seeder melepas status login di proses yang menjalankannya (`Auth::forgetGuards()`), agar cakupan jenjang user login tidak memengaruhi data.

## Perubahan pada data instalasi

- Nama jenjang `SLTP` menjadi "MTW/SMP" dan `SLTA` menjadi "TSN/SMA", hanya bila namanya masih bawaan instalasi. Jenjang SD tetap ada tetapi tidak berisi data.
- Jalur PSB `SUBSIDI` dibuat. Jalur bawaan `PRESTASI`, `TAHFIDZ`, dan `YATIM` dinonaktifkan (bila belum punya pendaftar), sehingga formulir hanya menampilkan `REGULER` dan `SUBSIDI`.

## Akun demo

| Peran | Email | Cakupan jenjang |
|---|---|---|
| super-admin | `super-admin@demo.test` | semua |
| bendahara | `bendahara@demo.test` | semua |
| admin-sekolah | `admin-sekolah@demo.test` | MTW/SMP |
| admin-psb | `admin-psb@demo.test` | semua |
| admin-konten | `admin-konten@demo.test` | semua |
| kesantrian | `kesantrian@demo.test` | TSN/SMA |
| musyrif | `musyrif@demo.test` | semua |
| ustadz-tahfidz | `ustadz-tahfidz@demo.test` | semua |
| petugas-pos | `petugas-pos@demo.test` | semua |

### Password

Semua akun demo memakai nilai `DEMO_PASSWORD` di `.env`. Bila kosong, dipakai password bawaan `DemoSeeder::DEFAULT_PASSWORD`:

```
demo-alkhair-belitang
```

**Peringatan:** password bawaan ini tertulis di dokumen publik. Untuk instance yang bisa diakses orang lain (mis. staging), isi `DEMO_PASSWORD` sebelum menjalankan seeder. Seeder menampilkan peringatan bila `DEMO_PASSWORD` kosong. Password hanya diatur saat akun dibuat; menjalankan ulang seeder tidak mengubah password akun yang sudah ada.

## Isi data

- Khusus putra. 3 tahun ajaran, 6 rombel per tahun (7A-9A MTW/SMP, 10A-12A TSN/SMA), 20 staff (Mudir, ustadz, musyrif, TU).
- 76 santri kurasi (aktif, lulus, keluar, pindah) beserta wali, riwayat kenaikan kelas, dan pindah jenjang MTW ke TSN, ditambah 3 santri baru hasil proses PSB.
- 2 asrama putra (Abu Bakar Ash-Shiddiq untuk MTW/SMP, Umar bin Khattab untuk TSN/SMA), 8 kamar, satu musyrif per kamar. Semua santri berombel tinggal di asrama; santri baru dari PSB belum ditempatkan sampai serah terima.
- Tagihan SPP empat bulan terakhir dengan pola lunas, cicilan, dan tunggakan, serta satu pembayaran yang dibatalkan.
- PSB: Gelombang I dan Gelombang II yang bersambung (pendaftaran dibuka sepanjang tahun selama kuota tersedia) dan 14 pendaftar di semua status.
- Dokumen santri (berkas PDF placeholder di disk `private`), surat keluar, prestasi, berita dengan kategori sesuai brief, dan album foto (gambar placeholder berpola netral tanpa teks). Logo cetak (kop surat dan kuitansi) memakai logo simbol dari `docs/brand/`. Konten tidak memuat perayaan selain Idul Fitri dan Idul Adha.

## Keterbatasan

- **Video sengaja kosong.** Seeder tidak membuat video dan tidak mengisi video profil maupun video PSB di beranda, karena kita tidak memakai ID YouTube milik orang lain. Admin dapat menambah video sungguhan lewat panel Konten > Video, lalu memilihnya di pengaturan beranda.
- Berkas dokumen dan foto hanya placeholder.
