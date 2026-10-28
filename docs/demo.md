# Data demo Pondok Pesantren Contoh

Data contoh untuk mencoba panel admin dan portal publik, untuk lembaga fiktif "Pondok Pesantren Contoh". Nama lembaga, alamat, semua nama orang, nomor telepon, email, nomor KK, dan isi berita **fiktif**. Program dan nominal biaya hanya contoh.

## Biaya demo

Tarif berlaku untuk MTW/SMP dan TSN/SMA:

| Kode | Biaya | Nominal |
|---|---|---|
| `SPP` | SPP bulanan (termasuk makan 3x sehari, laundry, operasional) | Rp1.600.000 |
| `FORMULIR` | Biaya pendaftaran | Rp300.000 |
| `UANG_MASUK` | Uang masuk | Rp10.000.000 |
| `DAFTAR_ULANG` | Daftar ulang tahunan: nominal **contoh** Rp1.500.000, sesuaikan dengan keputusan pondok | Rp1.500.000 (contoh) |

- SPP TSN/SMA angkatan kelas 12 memakai tarif lama terkunci Rp1.500.000 (contoh tarif angkatan).
- Tagihan tahunan tidak dibuat: belum ada generator tagihan tahunan, hanya jenis biaya dan tarifnya.
- Subsidi dan beasiswa terbatas untuk 3 penerima: Subsidi Yatim (bayar SPP Rp800.000), Beasiswa Tahfidz (50% SPP), dan Subsidi Uang Masuk (potongan Rp5.000.000 lewat jalur PSB `SUBSIDI`). Satu santri lain mendapat keringanan SPP Rp300.000 dari wawancara biaya.

## Menjalankan

```bash
php artisan migrate:fresh --force
php artisan db:seed --class=DemoSeeder
```

- Jalankan `php artisan migrate:fresh` lebih dulu. Revisi data demo ini (khusus putra, tanpa SD, tarif contoh) tidak memigrasikan data demo versi lama.
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

Selain akun di bawah, seeder juga membuat super-admin lembaga dari `SUPER_ADMIN_EMAIL` dan `SUPER_ADMIN_PASSWORD` di `.env` (bila keduanya terisi), sama seperti `php artisan migrate:fresh --seed`.

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
| wali-kelas | `wali-kelas@demo.test` | wali kelas 7A (MTW/SMP) |

### Password

Semua akun demo memakai nilai `DEMO_PASSWORD` di `.env`. Bila kosong, dipakai password bawaan `DemoSeeder::DEFAULT_PASSWORD`:

```
demo-pondok-contoh
```

**Peringatan:** password bawaan ini tertulis di dokumen publik. Untuk instance yang bisa diakses orang lain (mis. staging), isi `DEMO_PASSWORD` sebelum menjalankan seeder. Seeder menampilkan peringatan bila `DEMO_PASSWORD` kosong. Password hanya diatur saat akun dibuat; menjalankan ulang seeder tidak mengubah password akun yang sudah ada.

## Isi data

- Khusus putra. 3 tahun ajaran, 6 rombel per tahun (7A-9A MTW/SMP, 10A-12A TSN/SMA), 20 staff (Mudir, ustadz, musyrif, TU).
- 76 santri kurasi (aktif, lulus, keluar, pindah) beserta wali, riwayat kenaikan kelas, dan pindah jenjang MTW ke TSN, ditambah 3 santri baru hasil proses PSB.
- 2 asrama putra (Abu Bakar Ash-Shiddiq untuk MTW/SMP, Umar bin Khattab untuk TSN/SMA), 8 kamar, satu musyrif per kamar. Semua santri berombel tinggal di asrama; santri baru dari PSB belum ditempatkan sampai serah terima.
- Tagihan SPP empat bulan terakhir dengan pola lunas, cicilan, dan tunggakan, serta satu pembayaran yang dibatalkan.
- PSB: Gelombang I dan Gelombang II yang bersambung (pendaftaran dibuka sepanjang tahun selama kuota tersedia) dan 14 pendaftar di semua status.
- Dokumen santri (berkas PDF placeholder di disk `private`), surat keluar, prestasi, berita dengan kategori contoh, dan album foto (gambar placeholder berpola netral tanpa teks). Demo tidak memuat logo: portal menampilkan nama lembaga, dan admin dapat mengunggah logo sendiri di Profil lembaga. Konten tidak memuat perayaan selain Idul Fitri dan Idul Adha.
- Tahfidz: Halaqah Abu Hurairah (MTW/SMP, diampu akun `ustadz-tahfidz@demo.test`) dan Halaqah Zaid bin Tsabit (TSN/SMA) dengan setoran beberapa hari terakhir, satu ujian kenaikan juz 30 lulus bersyahadah, serta Halaqah Aisyah (putri, nonaktif) sebagai contoh halaqah putri. Capaian juz dihitung Action dari setoran dan ujian.
- Perizinan: periode "Libur Idul Fitri 1448 H" (belum dibuatkan izin pulang massal; coba aksi "Buat izin pulang massal" dari panel), tujuh izin di semua status (menunggu, disetujui, ditolak, di luar, sudah kembali, batal) termasuk satu santri yang masih di luar melewati batas kembali dan satu yang kembali terlambat, serta tiga kunjungan wali dengan titipan barang. Izin santri MTW/SMP diajukan dan disetujui akun `musyrif@demo.test` (Kamar 101), izin santri TSN/SMA oleh `kesantrian@demo.test`, keluar dan kembali dicatat `petugas-pos@demo.test`.
- Absensi: absensi harian sepuluh hari belajar terakhir (Jumat libur) untuk semua rombel tahun ajaran aktif, dicatat akun `admin-sekolah@demo.test` (MTW/SMP) dan super-admin demo (TSN/SMA), berisi Hadir, Sakit, dan Alpa; santri yang sedang izin keluar tercatat Izin otomatis. Akun `wali-kelas@demo.test` (Ustadz Fathur Rohman, wali kelas 7A) hanya melihat dan mencatat absensi 7A.
- Rapor diniyah: enam mapel per jenjang (Aqidah, Fiqih, Hadits, Bahasa Arab, Tahsin, Sirah Nabawiyah), empat di antaranya ber-KKM, dengan nilai semester 1 untuk semua santri rombel tahun ajaran aktif. Sebagian nilai di bawah KKM sehingga tanda "Di bawah KKM" dan "Belum tuntas" terlihat di input nilai dan rapor. Santri pertama tiap rombel punya catatan wali kelas.
- Kedisiplinan: katalog delapan jenis pelanggaran bawaan (ringan, sedang, berat) dan tiga ambang peringatan (25, 50, 75 poin), serta lima catatan pelanggaran beberapa hari terakhir, salah satunya dibatalkan. Catatan santri MTW/SMP dicatat akun `musyrif@demo.test` dan santri TSN/SMA oleh `kesantrian@demo.test`; rekap poin ada di panel Kedisiplinan > Rekap poin, dan wali melihat poin anaknya di portal wali.

## Keterbatasan

- **Video sengaja kosong.** Seeder tidak membuat video dan tidak mengisi video profil maupun video PSB di beranda, karena kita tidak memakai ID YouTube milik orang lain. Admin dapat menambah video sungguhan lewat panel Konten > Video, lalu memilihnya di pengaturan beranda.
- Berkas dokumen dan foto hanya placeholder.
