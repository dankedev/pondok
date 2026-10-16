# Data demo Ma'had Al-Khair Belitang

Data contoh untuk mencoba panel admin dan portal publik. Semua nama orang, alamat, nomor telepon, nomor KK, dan isi berita **fiktif**. Lembaga "Ma'had Al-Khair Belitang" (Belitang, Kab. OKU Timur, Sumatera Selatan) dipakai sebagai nama contoh.

## Menjalankan

```bash
php artisan migrate:fresh --force
php artisan db:seed --class=DemoSeeder
```

- Seeder memanggil `InstallSeeder` lebih dulu, jadi cukup dijalankan di database yang sudah dimigrasi.
- Seeder **menolak berjalan** bila `APP_ENV=production`.
- Seeder tidak dipanggil `DatabaseSeeder`, `InstallSeeder`, `pondok:install`, maupun `pondok:upgrade`.
- Aman dijalankan ulang: data yang sudah ada dilewati, tidak ada baris, berkas, atau pembayaran ganda.
- Tanggal dihitung dari hari ini (Asia/Jakarta): tahun ajaran lalu, aktif, dan tahun depan (gelombang PSB), tagihan empat bulan terakhir, serta pembayaran yang tidak pernah melewati hari ini.
- Bila seeding terputus di tengah, ulangi dari `php artisan migrate:fresh` agar alur PSB dan pembayaran lengkap.
- Seeder melepas status login di proses yang menjalankannya (`Auth::forgetGuards()`), agar cakupan jenjang user login tidak memengaruhi data.

## Akun demo

| Peran | Email | Cakupan jenjang |
|---|---|---|
| super-admin | `super-admin@demo.test` | semua |
| bendahara | `bendahara@demo.test` | semua |
| admin-sekolah | `admin-sekolah@demo.test` | SLTP |
| admin-psb | `admin-psb@demo.test` | semua |
| admin-konten | `admin-konten@demo.test` | semua |
| kesantrian | `kesantrian@demo.test` | SLTP dan SLTA |
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

- 3 tahun ajaran, 15 rombel per tahun (SD 4-6, SLTP dan SLTA putra/putri), 22 staff (Mudir, ustadz wali kelas, musyrif, TU).
- 88 santri kurasi (aktif, lulus, keluar, pindah) beserta wali, riwayat kenaikan kelas, dan pindah jenjang; 3 santri tambahan dari daftar ulang PSB.
- 2 asrama (putra dan putri), 8 kamar, penempatan dan pembina asrama.
- Jenis biaya, tarif (termasuk tarif angkatan dan tarif asrama putri), beasiswa, keringanan, tagihan bulanan empat bulan terakhir dengan pola lunas, cicilan, dan tunggakan, serta satu pembayaran yang dibatalkan.
- Gelombang PSB tahun depan dengan 14 pendaftar di semua status.
- Dokumen santri (berkas PDF placeholder di disk `private`), surat keluar, prestasi, berita, album foto (gambar placeholder), dan video.

## Keterbatasan

- **Video YouTube** memakai ID rekaan (`DmAlKhr0001` dan seterusnya), jadi embed dan thumbnail kosong. Ganti dengan video lembaga sendiri lewat panel Konten > Video.
- Logo sekolah dan tautan media sosial sengaja dikosongkan.
- Berkas dokumen dan foto hanya placeholder.
