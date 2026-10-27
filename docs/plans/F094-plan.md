# F094 Onboarding setup awal di dashboard

Issue: #115 · Jalur: cepat · Modul: inti · Lanjutan F018/F066

## Tujuan
Instalasi baru langsung memandu super-admin: daftar langkah setup dengan status dari data nyata, bar progres + persentase, tombol ke halaman admin, dan tautan panduan pondok.org/docs.

## Perubahan
- `App\Actions\Dashboard\SummarizeSetupProgress` (hanya membaca): daftar langkah berlaku, status selesai, persentase. Langkah milik modul mati atau tabelnya belum ada tidak ikut.
- `App\Filament\Widgets\SetupChecklistWidget` + `resources/views/filament/widgets/setup-checklist.blade.php`: `$sort = -10` (paling atas), lebar penuh, `canView()` = super-admin dan progres < 100%. Widget inti (tanpa saklar modul), didaftarkan ke daftar inti `ModuleComplianceTest`.
- `lang/id/pondok.php`: `pondok.dashboard.setup.*`.
- Tanpa tombol "Sembunyikan" (YAGNI; widget hilang sendiri di 100%), tanpa tabel baru.

## Langkah dan sumber data
| Langkah | Modul | Selesai bila | Tombol | Panduan |
|---|---|---|---|---|
| Profil lembaga | inti | `school.name`, `school.logo_path`, dan data kontak (`GetSchoolProfile::hasContactData`) terisi | Pengaturan → Identitas | `pengenalan` |
| Jenjang | inti | ada `units` | Jenjang | `pengenalan` |
| Tahun ajaran aktif | inti | `AcademicYear::current()` | Tahun ajaran | `pengenalan` |
| Modul | inti | ada modul yang dimatikan, atau log `SetModuleEnabled` | Pengaturan → Modul | `modul` |
| Rombel | akademik | ada `classrooms` | Rombel | `import-data` |
| Data santri | akademik | ada `students` | Santri (input/impor) | `import-data` |
| Staf & peran | inti | ada user berperan selain super-admin/wali | Pengguna | `pengenalan` |
| Tarif biaya | keuangan | ada `fee_rates` | Tarif biaya | `pengenalan` |
| Email | inti | `CheckSystemHealth` `mail` = ok | — | `konfigurasi-env` |
| Cron & antrean | inti | `CheckSystemHealth` `scheduler` dan `queue` = ok | — | `cron-antrean` |

Tautan: `https://pondok.org/docs/<slug>/` (slug diverifikasi di repo pondok-marketing); tautan umum `https://pondok.org/fitur/`. Tombol hanya bila route ada dan boleh diakses.

## Kriteria penerimaan
1. Data instalasi (`InstallSeeder`) menampilkan widget dengan progres < 100% dan langkah belum selesai.
2. Status tiap langkah berubah sesuai data (test per langkah).
3. Langkah modul nonaktif tidak muncul dan tidak memengaruhi persentase.
4. Peran selain super-admin tidak melihat widget.
5. Pada 100% widget tidak tampil.
6. Semua tautan panduan memakai slug yang ada.
7. Rapi di 360px, 1024px, desktop, dan dark mode; teks lewat `lang/id`.

## Kontrak desain
- Pengguna: super-admin setelah instalasi. Aksi utama: tombol langkah berikutnya yang belum selesai.
- Urutan baca: judul + "x dari y langkah" + persentase → bar progres → daftar langkah (ikon status, judul, keterangan, tombol, Panduan).
- State: belum selesai (ikon lingkaran abu), selesai (ikon centang `success`, teks dicoret tidak dipakai), 100% (tidak tampil), tanpa akses (tidak tampil).
- 360px: baris langkah menumpuk, tombol turun ke bawah teks; tanpa scroll horizontal.

## Batasan
Langkah Modul bisa kembali "belum" bila log aktivitas dipangkas dan semua modul aktif; diterima (jarang, widget hanya panduan).
