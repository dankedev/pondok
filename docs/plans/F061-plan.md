# F061 inti-import-staff — Import data pegawai (CSV)

Issue: #30
Status rencana: DRAFT
Jalur: penuh (permission baru + Action yang mengubah data). Fitur kecil, mengikuti pola import F0xx yang sudah ada.

## Ringkasan & tujuan
Admin mengimpor data pegawai (`staff`) dari CSV lewat halaman daftar Staff. Keputusan user (19 Okt 2026): **data pegawai saja**, akun login dan peran tetap dibuat manual; format CSV saja.

## Ruang lingkup
- Termasuk: `StaffImporter`, Action `ImportStaffRow`, tombol "Unduh template CSV" dan "Import" di `ListStaff`, permission `import:staff`, teks lang.
- Tidak termasuk: akun login/peran (`user_id` tidak pernah disentuh), XLSX, ekspor, migration (tabel `staff` sudah ada), cakupan jenjang (staff tidak berjenjang), penghapusan pegawai yang tidak ada di file.

## Model data
Tanpa migration. `staff`: `nip` nullable unique, `name`, `gender` enum L|P, `phone` nullable, `position`, `is_active` boolean default true. Tanpa soft delete.

## Action class
`app/Actions/Core/ImportStaffRow` (modul inti memakai folder `Core`; tidak ada folder `Inti`). `handle(array $row): Staff`, melempar `RowImportFailedException` berpesan Indonesia.
- Normalisasi: trim semua kolom; `gender` huruf besar (castStateUsing di importer, seperti StudentImporter); `is_active` `ya`/`tidak` huruf kecil (pola `guardian_billing` StudentImporter), kosong = tidak ditentukan.
- `nip` terisi: cari staff dengan NIP persis (setelah trim). Ketemu → perbarui, tidak → buat.
- `nip` kosong: cocokkan `name` + `phone` (trim, tidak peka huruf besar/kecil, dibandingkan di PHP seperti `sameName` di ImportRoomAssignmentRow; telepon kosong cocok dengan telepon kosong). Tepat satu cocok → perbarui, tidak ada → buat, lebih dari satu → baris gagal (pesan: data ganda/ambigu, perbaiki manual; sama dengan pola ImportRoomAssignmentRow). Baris tanpa NIP tidak pernah mengubah NIP.
- Perbarui: `name`, `gender`, `position` selalu diisi dari file; `phone`, `nip`, `is_active` hanya bila kolomnya terisi (kosong tidak menghapus). Buat: `is_active` default aktif bila kosong.
- `user_id` tidak pernah dibaca atau diubah. Idempoten: mengimpor file yang sama dua kali tidak menambah baris.

## UI (Filament)
- `app/Filament/Imports/StaffImporter` (turunan `CsvImporter`, `columnLangPrefix` = `pondok.staff.import.columns`), `saveRecord()` memanggil Action. Validasi kolom di importer: `name` dan `position` wajib (max 255 dan 100), `gender` wajib `in:L,P`, `nip` max 50, `phone` max 30, `is_active` `in:ya,tidak`. Baris tidak valid masuk "baris gagal" lewat mekanisme CsvImporter.
- Kolom template: `nip, name, gender, phone, position, is_active`. Contoh fiktif: NIP `197001012026001`, `Ustadz Contoh`, `L`, `0800000000001`, `Ustadz`, `ya`.
- `ListStaff` memakai `HasCsvImportActions` + `csvImportActions(...)`, visible bila `can('import', Staff::class)`. Tambah `StaffPolicy::import` → `can('import:staff')`.
- `lang/id/pondok.php` di `staff.import`: `completed`, `template_file_name` (`template-import-pegawai.csv`), `modal_description` (kolom, nama jabatan bebas, kosong tidak menghapus, hasil di lonceng), `columns`, dan `errors` bila perlu. Design-guide: hanya pola import yang ada, tanpa elemen UI baru.

## Permission & cakupan
- Permission baru `import:staff` lewat `EXTRA_RESOURCE_ACTIONS['Staff'] = ['import']`. Peran yang mengelola Staff: `super-admin` (otomatis semua) dan `admin-sekolah` (`resourcePermissions('Staff', [..., 'import'])`). Peran lain tidak.
- Tanpa `unit_user`. Tanpa saklar modul (Staff ada di inti).

## Kriteria penerimaan
- AC-1: Baris tanpa NIP dan tanpa kecocokan nama+telepon → membuat `staff` baru; `is_active` kosong → aktif; `user_id` null.
- AC-2: NIP sudah ada → baris itu diperbarui (name, gender, position, phone), jumlah baris `staff` tetap; `user_id` staff itu tidak berubah.
- AC-3: Tanpa NIP, nama+telepon cocok (huruf besar/kecil dan spasi tepi diabaikan) → memperbarui, bukan membuat dobel; nama sama dengan telepon berbeda → membuat baru.
- AC-4: Kolom opsional kosong saat memperbarui (phone, nip, is_active) tidak mengubah nilai lama; `is_active` `tidak` menonaktifkan, `ya` mengaktifkan.
- AC-5: Mengimpor baris yang sama dua kali tidak menambah baris `staff`.
- AC-6: Baris invalid (gender bukan L/P, termasuk huruf kecil `l` — normalisasi sebelum rule, name atau position kosong, is_active bukan ya/tidak) → gagal dengan pesan Bahasa Indonesia, tidak ada baris `staff` tertulis.
- AC-7: Tombol "Unduh template CSV" dan "Import" di `ListStaff` terlihat hanya bagi pemilik `import:staff` (admin-sekolah, super-admin); peran lain tidak melihatnya.
- AC-8: Tanpa NIP, dua staff lama dengan nama+telepon sama → baris gagal berpesan ambigu, tidak ada yang berubah atau bertambah (di `ImportStaffRowTest`).
- AC-9: Import CSV end-to-end lewat `ListStaff`: dua baris valid dan satu invalid → dua staff tersimpan, satu baris gagal tercatat dengan alasan, notifikasi memuat jumlah sukses dan gagal.
- AC-10: Seeder: `import:staff` ada, dimiliki persis `super-admin` dan `admin-sekolah`; daftar permission di `RolePermissionSeederTest` diperbarui persis.

## Rencana test (ramping, hanya perilaku baru)
Perilaku dasar CsvImporter/HasCsvImportActions (BOM, delimiter, formula injection, notifikasi database) sudah diuji di F0xx lain dan **tidak diulang**.
- `tests/Feature/Actions/Core/ImportStaffRowTest.php`: AC-1 sampai AC-6 dan AC-8 (satu test per kelompok, dataset untuk invalid).
- `tests/Feature/Filament/Staff/ImportStaffTest.php` (pola `ImportRoomAssignmentsTest`): AC-7 (termasuk cek header template berisi kolom pegawai dan contoh telepon `0800...`), AC-9.
- `tests/Feature/Database/Seeders/RolePermissionSeederTest.php`: tambah `import:staff` pada `admin-sekolah`, dan cek daftar permission persis (AC-10).

## Urutan implementasi
1. Paket A (data + Action): `ImportStaffRow`, lang errors. Hijau: `ImportStaffRowTest`.
2. Paket B (permission): `EXTRA_RESOURCE_ACTIONS`, seeder admin-sekolah, `StaffPolicy::import`. Hijau: `RolePermissionSeederTest`.
3. Paket C (Filament): `StaffImporter`, `ListStaff`, lang. Hijau: `ImportStaffTest`.
Pint pada file PHP yang berubah. Suite penuh dijalankan sekali oleh sesi utama.

## Risiko & pertanyaan terbuka
- Nama+telepon ganda di database: baris ditolak sebagai ambigu (keputusan sesi, selaras pola import lain; menimpa staf yang salah lebih buruk daripada menolak). AC-nya perlu satu test.
- Perubahan di `RolePermissionSeeder` tidak memengaruhi instalasi lama sampai seeder dijalankan ulang (pola sama dengan `import:*` sebelumnya).

## Riwayat revisi
(belum ada)

## Review #1 — 2026-10-19

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | Kriteria penerimaan / Rencana test | Perilaku nama+telepon ambigu (>1 cocok → baris gagal) sudah diputuskan dan tercantum di Action serta "Risiko", tetapi tidak punya AC; rencana test hanya menyebut "perlu satu test". Perilaku data-rusak-bila-salah ini harus terpetakan ke AC. | Tambah AC-11: tanpa NIP, dua staff lama dengan nama+telepon sama → baris gagal berpesan ambigu, tidak ada staff berubah/bertambah. Petakan ke `ImportStaffRowTest`. |
| 2 | minor | Action / DRY | `sameName` di `ImportRoomAssignmentRow` bersifat private; rencana "seperti" itu, risiko disalin. | Boleh salin satu helper kecil (belum ada pemakai ketiga); jangan buat trait baru. |
| 3 | minor | AC-6 / importer | Validasi `in:ya,tidak` dan `in:L,P` harus berlaku pada nilai yang sudah dinormalisasi huruf (castStateUsing) agar `YA`/`l` tidak gagal tanpa alasan. | Tegaskan urutan cast sebelum rule; tambah satu nilai huruf besar di dataset test. |
| 4 | minor | AC-8 / AC-9 | AC-8 mengulang dasar template CsvImporter. | Gabung ke test AC-7 (cukup cek header kolom pegawai), tidak perlu test sendiri. |

Catatan: permission `import:staff` (EXTRA_RESOURCE_ACTIONS, admin-sekolah pada RolePermissionSeeder baris ~201, StaffPolicy::import) selaras pola `import:room-assignment`. Pakai ulang CsvImporter/HasCsvImportActions benar, tanpa migration, ukuran wajar. Tanpa blocker.
