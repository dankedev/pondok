# F097 Data demo mapel, nilai, dan absensi

Issue: #123
Jalur: cepat (data demo, tanpa migration, tanpa Action baru)
Bergantung pada: F096 (kolom `passing_score`)

## Tujuan
Setelah `migrate:fresh --seed` lalu `db:seed --class=DemoSeeder`, halaman mapel diniyah, input nilai,
rapor diniyah, dan absensi berisi data yang wajar, termasuk nilai di bawah KKM.

## Perubahan
- `database/seeders/Demo/DemoDiniyah.php` (baru): enam mapel per jenjang demo (Aqidah, Fiqih, Hadits,
  Bahasa Arab, Tahsin, Sirah Nabawiyah), empat ber-KKM. Mapel dibuat `firstOrCreate` (belum ada Action
  mapel; resource Filament pun menyimpan langsung). Nilai semester 1 tahun ajaran aktif untuk semua rombel
  lewat `SaveDiniyahGrades` oleh super-admin demo, deterministik dari NIS (58–98) sehingga sebagian di bawah
  KKM. Santri pertama tiap rombel diberi catatan wali kelas. Nilai hanya dibuat bila tabel nilai kosong.
- `database/seeders/Demo/DemoAttendance.php`: dari 7A/8A lima hari menjadi semua rombel tahun ajaran aktif
  sepuluh hari belajar. MTW/SMP tetap dicatat admin-sekolah, jenjang lain oleh super-admin (cakupan).
- `database/seeders/DemoSeeder.php`: memanggil `DemoDiniyah`.
- `docs/demo.md`: daftar isi demo diperbarui.
- Test: `tests/Feature/Database/Seeders/DemoDiniyahTest.php` (baru); `AbsensiModuleTest` AC-13 disesuaikan
  karena sesi TSN/SMA kini dicatat super-admin.

## Kriteria penerimaan
1. Ada 12 mapel diniyah (6 per jenjang demo), sebagian dengan KKM dan sebagian tanpa.
2. Nilai tersimpan untuk santri rombel tahun ajaran aktif, dan sebagian di bawah KKM.
3. Setiap rombel aktif yang punya santri memiliki absensi beberapa hari terakhir.
4. Menjalankan seeder ulang tidak menambah mapel, nilai, catatan, atau sesi absensi.
5. Seeder tetap ditolak di production (perilaku `DemoSeeder` yang ada).
6. Konten mengikuti manhaj Ahlus Sunnah (tanpa perayaan selain Idul Fitri dan Idul Adha).
