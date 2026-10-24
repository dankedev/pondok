# PRD Pondok — Sistem Informasi Pesantren & Sekolah Terpadu

Versi 2.0 · Oktober 2026

## 1. Ringkasan

Pondok (pondok.org) adalah aplikasi open source yang menggabungkan portal publik dan sistem administrasi untuk pondok pesantren dan sekolah berjenjang (SD, SLTP, SLTA). Fokusnya pada kebutuhan yang khas pesantren: santri mukim, asrama dan musyrif, tahfidz, perizinan, serta keuangan dengan banyak jenis biaya dan beasiswa.

### Tujuan

1. Mengurangi pekerjaan manual bendahara dan panitia PSB, terutama penagihan, rekap pembayaran, dan verifikasi berkas.
2. Menyatukan data santri dari pendaftaran sampai lulus: kelas, asrama, hafalan, prestasi, dokumen, dan keuangan.
3. Mudah dipasang oleh sekolah lain di hosting murah (shared hosting cPanel) tanpa staf IT khusus.

### Di luar lingkup

- Multi-tenant (SaaS banyak sekolah dalam satu instalasi). Satu instalasi untuk satu sekolah.
- Rapor kurikulum formal yang sudah ditangani aplikasi resmi pemerintah. Cukup ekspor data ke format EMIS/Dapodik.

## 2. Pengguna & peran

| Peran | Akses utama |
|---|---|
| Super Admin | Semua modul dan pengaturan |
| Bendahara | Keuangan: tarif, beasiswa, tagihan, pembayaran, laporan |
| Admin Konten | Berita, kegiatan, prestasi publik, galeri, video, halaman, profil lembaga |
| Admin Sekolah | Data santri, kelas, staff, dokumen, absensi, pengaturan profil |
| Admin PSB | Gelombang, jalur, verifikasi pendaftar dan berkas |
| Kesantrian | Asrama, perizinan, kepulangan, kunjungan, prestasi |
| Musyrif | Santri di asrama/kamarnya, persetujuan izin |
| Ustadz Tahfidz | Halaqah, setoran, ujian |
| Petugas Pos | Mencatat keluar-masuk santri dan kunjungan |
| Wali Kelas | Absensi harian rombel yang ia walikan |
| Wali (Fase 2) | Portal wali: tagihan, pembayaran, perkembangan anak |

Akses diatur dua lapis:
- **Permission** menentukan modul dan aksi yang boleh dibuka.
- **Cakupan jenjang** menentukan data jenjang mana yang terlihat. Contohnya, Admin Sekolah yang hanya mengurus SLTP.

## 3. Modul & kebutuhan fungsional

### 3.1 Portal Publik & Konten
- Berita, kegiatan, dan prestasi. Berita prestasi mengambil data dari modul Prestasi.
- Galeri foto berkategori (fasilitas, kegiatan), video YouTube, dan halaman statis yang bisa dibuat dan dihapus admin dengan status draf/terbit (tiga halaman awal: profil, visi-misi, kontak; semua setara dan hanya dibedakan template Biasa, Kontak, atau Info PSB), serta navigasi portal sebagai daftar tautan: menu utama dengan submenu satu tingkat dan tombol header opsional, plus footer berupa paling banyak 4 widget yang disusun admin (Teks dengan opsi logo, Tautan, Media sosial, Kontak; Media sosial dan Kontak menampilkan semua data Kontak & sosial) dengan baris hak cipta tetap.
- Beranda disusun admin dari blok (Hero, Teks dan gambar, Keunggulan, Kutipan, Ajakan, Video, Konten terbaru): tambah, urutkan, sembunyikan, dan hapus blok tanpa ubah kode. Instalasi baru tidak membawa isi beranda bawaan; tanpa blok, beranda hanya menampilkan nama lembaga. Badge dan tombol pendaftaran PSB di beranda tampil otomatis saat gelombang dibuka.
- Profil sekolah diatur dari panel (`settings`) tanpa ubah kode. Menu admin: grup **Situs** (Beranda, Navigasi, Footer, Halaman, Berita, Kategori, Galeri, Video) dan grup **Pengaturan** (Identitas, Kontak & sosial). *Kontak & sosial* menjadi satu-satunya sumber alamat, email, tautan peta, daftar kontak, dan media sosial; satu kontak bisa ditandai untuk dicetak di kop surat dan kuitansi, dan kontak mana pun bisa ditandai tampil di halaman Info PSB.

### 3.2 Akademik & Santri
- Satu tabel santri untuk semua jenjang, dibedakan per jenjang dan angkatan.
- Rombel per tahun ajaran (1A, 1B, 1C), mendukung kelas putra/putri/campur, dengan wali kelas.
- Riwayat kelas per tahun ajaran. Mendukung kenaikan kelas dan perpindahan jenjang.
- Data wali, termasuk wali yang sama untuk beberapa santri bersaudara. Satu wali ditandai sebagai kontak tagihan.
- Data staff (ustadz, musyrif, TU), tidak wajib punya akun login.

### 3.3 Asrama
- Asrama (putra/putri) dan kamar dengan kapasitas.
- Penempatan santri dengan riwayat pindah kamar. Santri tanpa penempatan aktif dianggap nonmukim.
- Musyrif dan kepala asrama per asrama atau per kamar, per tahun ajaran.
- Validasi gender dan kapasitas saat penempatan.

### 3.4 PSB
- Gelombang per tahun ajaran, jalur pendaftaran (Reguler, Prestasi, Tahfidz, Yatim), dan syarat berkas per jenjang.
- Halaman Info PSB publik di `/info-psb` (menu Info PSB, aktif bila modul PSB aktif): isi bebas dari halaman bertemplate Info PSB (opsional), lalu bagian otomatis gelombang yang dibuka dan akan dibuka, syarat berkas per jenjang, biaya per jenjang untuk gelombang yang dibuka (bila modul keuangan aktif), dan kontak bertanda PSB. Bagian tanpa data tidak tampil.
- Pendaftaran online tanpa akun. Pendaftar cek status dan unggah berkas memakai nomor pendaftaran + token.
- Status: draft, diajukan, diverifikasi, diterima, cadangan, ditolak.
- Biaya formulir saat daftar, lalu uang pangkal/seragam/kitab saat diterima. Bisa dicicil, dan potongan jalur diterapkan otomatis.
- Daftar ulang mengubah pendaftar menjadi santri. Tagihan, keringanan, dan berkas valid ikut pindah.

### 3.5 Keuangan
- Jenis biaya yang bisa diatur: SPP, asrama, makan, laundry, formulir, uang pangkal, dan lainnya, dengan frekuensi bulanan, tahunan, saat daftar, saat diterima, atau manual.
- Tarif per jenjang, per angkatan, per asrama, dan per gelombang PSB, berlaku mulai tahun ajaran tertentu. Tarif bisa berubah tiap tahun atau tetap.
- Beasiswa dan keringanan per santri dan per jenis biaya, dengan masa berlaku. Mode: nominal, potongan rupiah, atau persen. Program beasiswa dan donaturnya tercatat.
- Tagihan bisa digabung atau dipisah per kelompok biaya, diatur dari panel admin.
- Generator tagihan bulanan otomatis dan aman dijalankan ulang.
- Bendahara bisa mengimpor saldo tunggakan awal dari CSV (satu baris per santri dan jenis biaya) sebelum generator bulanan pertama; hasilnya tagihan kelompok `SALDO_AWAL`.
- Pembayaran tunai, transfer, atau payment gateway, termasuk cicilan yang dialokasikan ke item sesuai prioritas. Setiap pembayaran mendapat kuitansi bernomor.
- Laporan: tagihan bulan berjalan, terbayar vs menunggak, rasio koleksi, santri menunggak teratas, dan total subsidi beasiswa per program.

### 3.6 Dokumen
- Satu repositori dokumen untuk santri, wali, staff, dan prestasi, dengan jenis dokumen yang bisa diatur.
- Verifikasi, masa berlaku, riwayat versi, dan cek kelengkapan berkas wajib.
- Nomor KK/NIK dienkripsi. Dokumen rahasia hanya bisa dibuka peran tertentu.
- Surat keluar (keterangan aktif, rekomendasi, pindah, syahadah, kartu santri) dengan penomoran otomatis dan QR verifikasi keaslian.

### 3.7 Prestasi
- Prestasi individu atau beregu, dengan kategori, tingkat, peringkat, sertifikat, dan foto.
- Rekap per santri dan per tahun. Bisa diterbitkan sebagai berita di website.

### 3.8 Tahfidz (Fase 3, skema disiapkan)
- Halaqah per tahun ajaran dengan ustadz pengampu.
- Setoran ziyadah, murajaah, dan tasmi' berbasis rentang surah dan ayat, dengan nilai dan jumlah kesalahan.
- Capaian per juz, target per jenjang dan tingkat, serta ujian dan syahadah.

### 3.9 Perizinan (Fase 3)
- Jenis izin dengan batas durasi, pihak yang menyetujui (musyrif, kepala asrama, atau kesantrian), dan wajib dijemput wali. Persetujuan otomatis diarahkan ke musyrif atau kepala asrama santri menurut kamar aktifnya; kesantrian bisa memutus semua izin.
- Alur status: menunggu → disetujui/ditolak/batal, disetujui → di luar/batal, di luar → sudah kembali. Satu santri hanya boleh punya satu izin yang berjalan.
- Pencatatan keluar dan kembali oleh petugas pos, serta daftar dan widget dashboard santri yang terlambat atau belum kembali (terlambat dihitung dari batas kembali, bukan status).
- Pulang massal per periode libur (mis. Idul Fitri, Idul Adha) membuat izin pulang untuk santri mukim aktif sekaligus, aman dijalankan ulang.
- Kunjungan wali (sambangan) dengan catatan titipan barang.
- Belum termasuk: pengajuan izin lewat portal wali, notifikasi WhatsApp ke wali, dan kartu/QR izin untuk pos.

### 3.10 Dashboard
- Satu halaman yang menyesuaikan peran. Setiap widget dicek permission-nya: `view-content-metrics`, `view-psb-metrics`, `view-student-metrics`, `view-finance-metrics`, `view-settings-metrics`, dan `view-dashboard` untuk akses halaman.
- Widget: jumlah konten, statistik PSB (total, status, 7 hari terakhir), santri aktif per jenjang dan angkatan, keuangan bulan berjalan, kelengkapan dokumen, dan prestasi terbaru.
- Tetap render ketika modul atau tabel belum tersedia, dengan pesan "Modul belum tersedia".

### 3.11 Kesehatan (Fase 4)
- Catatan kunjungan/keluhan kesehatan santri: waktu, keluhan, tindakan/obat, perlu rujukan, perlu izin pulang, dan catatan. Teks keluhan dan tindakan disimpan terenkripsi.
- Riwayat per santri (tab di halaman santri) dan daftar dengan filter tanggal, jenjang, dan rujukan.
- Kesantrian mencatat dan mengoreksi; musyrif mencatat untuk santri binaannya; hapus hanya super-admin.
- Belum termasuk: stok obat, rekam medis lengkap, integrasi ke Perizinan, tampilan di portal wali, dan notifikasi wali.

### 3.12 Absensi (Fase 3)
- Absensi harian per rombel: satu catatan per rombel per tanggal, status setiap santri aktif di rombel itu Hadir, Sakit, Izin, atau Alpa (bawaan Hadir). Tanggal tidak boleh di masa depan dan harus di dalam tahun ajaran rombel.
- Disimpan ulang kapan saja tanpa data ganda; santri yang baru masuk rombel ikut muncul.
- Admin Sekolah mencatat semua rombel dalam cakupan jenjangnya; Wali Kelas hanya rombel yang ia walikan; Kesantrian hanya melihat.
- Bila modul Perizinan aktif, santri yang seharian penuh di luar pondok karena izin (setelah hari keluar dan sebelum hari kembali, atau masih di luar) otomatis Izin dan tidak bisa diubah. Hari keluar, hari kembali, dan izin yang belum keluar hanya diberi petunjuk.
- Rekap bulanan per rombel (jumlah H/S/I/A per santri) di panel admin.
- Portal wali menampilkan jumlah H/S/I/A bulan berjalan dan daftar hari tidak hadir per anak.
- Belum termasuk: absensi per mata pelajaran atau kegiatan asrama, keterangan per santri, notifikasi WhatsApp, kalender hari efektif, ekspor, dan hapus catatan absensi.

## 4. Kebutuhan teknis

- Laravel 13, PHP 8.3+, Filament untuk panel admin, Blade + Livewire + Tailwind untuk halaman publik.
- Spatie Permission untuk RBAC, Spatie Activitylog untuk audit keuangan.
- MySQL/MariaDB di produksi, SQLite di development, tetap kompatibel dengan Postgres.
- Harus berjalan di shared hosting: queue driver database, scheduler lewat satu cron, tanpa Redis, tanpa Node saat runtime.
- Setiap modul (selain inti) bisa diaktifkan atau dimatikan.
- Antarmuka berbahasa Indonesia. Kode dan skema berbahasa Inggris.
- Perlindungan data pribadi sesuai UU PDP: enkripsi kolom sensitif, disk private, akses berbasis permission, dan audit log.

Skema lengkap ada di `docs/erd.md`.

## 5. Kriteria penerimaan

1. Peran tanpa permission tidak bisa mengakses modul atau widget sensitif (diuji per peran).
2. Pengguna dengan cakupan jenjang hanya melihat data jenjangnya.
3. Generator tagihan:
   - menerapkan tarif sesuai urutan fallback dan `effective_from_year`;
   - menerapkan ketiga mode keringanan dengan benar;
   - tidak membuat tagihan ganda saat dijalankan ulang;
   - mengikuti pengaturan gabungan atau terpisah;
   - tidak menagih biaya asrama ke santri nonmukim.
4. Cicilan pada tagihan gabungan teralokasi sesuai prioritas, dan status tagihan diperbarui otomatis.
5. Konversi pendaftar menjadi santri memindahkan tagihan, keringanan, dan berkas valid.
6. Dashboard tetap render tanpa error ketika tabel modul belum ada. Link widget hanya tampil bila route tersedia.
7. Aplikasi bisa dipasang dan berjalan di shared hosting cPanel dengan PHP 8.3 dan MySQL.

## 6. Tahapan

| Fase | Lingkup |
|---|---|
| 1 — MVP | Inti & RBAC, Akademik & Santri, Asrama, PSB, Keuangan, Konten, Dokumen, Prestasi, Dashboard |
| 2 | Virtual account/payment gateway, notifikasi WhatsApp, portal wali |
| 3 | Tahfidz, Perizinan, absensi, kedisiplinan |
| 4 | Rapor diniyah, tabungan/uang saku, kas umum, kepegawaian & honor, kesehatan, alumni & donasi |

Fase 1 dipakai penuh di satu lembaga pengguna selama satu siklus PSB sebelum prioritas fase berikutnya ditetapkan.

## 7. Pertanyaan terbuka

- Payment gateway dan bank mana yang dipakai lembaga pengguna?
- WhatsApp: gateway resmi (berbayar per percakapan) atau tidak resmi?
- Format nomor induk santri, nomor kuitansi, dan nomor surat yang berlaku saat ini.
- Standar mushaf untuk hitungan halaman tahfidz (default 15 baris).
