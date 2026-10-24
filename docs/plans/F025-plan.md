# F025 absensi — Absensi harian per rombel

Issue: #6
Jalur: penuh (migration, permission + peran baru, Action yang mengubah data)
Status rencana: APPROVED

## Ringkasan & tujuan
Admin sekolah dan wali kelas mencatat kehadiran harian santri per rombel (Hadir/Sakit/Izin/Alpa). Santri yang sedang berizin di modul Perizinan otomatis tercatat Izin. Rekap bulanan per rombel di admin, dan rekap bulan berjalan per anak di portal wali. Rujukan: PRD §3.12, ERD "Absensi". Pola acuan: F024 (`docs/plans/F024-plan.md`), F031, `AchievementRecap`.

## Ruang lingkup
- Termasuk: modul `absensi`, 2 tabel, enum status, 2 Action, 1 resource + 1 page rekap Filament, peran bawaan `wali-kelas` + permission, cakupan jenjang + cakupan wali kelas, bagian kehadiran di rincian santri portal wali, data demo, `lang/id`, ERD "Absensi" + PRD §3.12 + design-guide §3/§4 (sudah ditulis bersama rencana ini).
- Tidak termasuk: absensi per mapel/jam pelajaran, absensi kegiatan asrama/shalat, notifikasi WhatsApp, catatan/keterangan per santri (lihat D6), hapus sesi absensi, kalender hari efektif/libur, ekspor/cetak, widget dashboard, activity log, persentase kehadiran di rapor.

## Keputusan
- **D1 Modul** `Module::Absensi = 'absensi'`, `requires()` = `[Akademik]`. Saklar dari `SettingSeeder` (`Module::cases()`, `firstOrCreate` bernilai `true`), jadi instance lama yang menjalankan `pondok:upgrade` (`SystemSeeder` = `SettingSeeder` + `RolePermissionSeeder`) langsung punya modul aktif dan peran `wali-kelas`; tanpa seeder itu peran belum ada dan saklar dibaca `false`. Integrasi Perizinan hanya bila `Module::Perizinan->isEnabled()`.
- **D2 Cakupan rombel** satu sumber `AttendanceSession::visibleClassrooms(User): Builder<Classroom>`: rombel dalam `scopedUnitIds()` user; pemegang `view-any:student` (super-admin, admin-sekolah, kesantrian) → semua rombel itu; selain itu → hanya `homeroom_staff_id` ∈ `Staff where user_id = user.id` (wali kelas; user tanpa staff → kosong). Sama seperti F024 D5, tidak ada permission "any" terpisah.
- **D3 Visibilitas sesi**: hanya global scope `visible` untuk user login = `whereIn(classroom_id, visibleClassrooms(user))`, pola `LeaveRequest::booted`. Cakupan jenjang sudah termasuk di `visibleClassrooms`, jadi tanpa `HasUnitScope` (tidak dobel). Portal wali memakai `withoutGlobalScopes()` secara eksplisit.
- **D4 Roster** = santri `aktif` (tidak terhapus) yang punya `enrollments.classroom_id` = rombel itu. Santri yang keluar dari roster tetap menyimpan baris lamanya (riwayat), tidak ikut di lembar berikutnya.
- **D5 Izin dari Perizinan** (keputusan orkestrator, Review #1 T1/T2). Dua scope di `LeaveRequest`, dipanggil dengan `withoutGlobalScope('visible')` + `withoutUnitScope()` (wali kelas tidak punya permission izin):
  - `scopeLockingDate($date)` — **mengunci `izin`** hanya untuk hari yang sepenuhnya di luar: status `keluar` dan `departed_at < tanggal 00:00` (batas atas terbuka, termasuk yang lewat `return_due`, sampai dicek kembali; keterlambatan dipantau di Perizinan), atau status `kembali` dan `departed_at < tanggal 00:00` dan `returned_at >= tanggal+1 00:00`. Izin `disetujui` yang belum dicek keluar tidak mengunci. Santri terkunci dipaksa `izin` apa pun inputnya.
  - `scopeTouchingDate($date)` — **petunjuk saja**: status `disetujui`/`keluar`/`kembali` dengan `COALESCE(departed_at, planned_departure) <= tanggal 23:59:59` dan (`keluar` atau `COALESCE(returned_at, return_due) >= tanggal 00:00`). Hari keluar, hari kembali, izin sehari, dan izin yang belum keluar: baris tetap bisa diedit, bawaan `hadir`, form menampilkan jenis izin sebagai petunjuk.
  - Perbandingan memakai batas datetime (bukan `DATE()`), portabel SQLite/MySQL/Postgres. Dihitung ulang setiap simpan; baris lama tidak berubah sampai sesi disimpan lagi.
- **D6 Tanpa kolom catatan** (YAGNI + UU PDP: keterangan sakit adalah data kesehatan). Status saja.
- **D7 Tanggal** wajib `<= today()` (zona aplikasi) dan di dalam `starts_on..ends_on` tahun ajaran rombel.
- **D8 Tanpa hapus**: koreksi lewat edit status. Salah tanggal/rombel tidak bisa dihapus dari panel (dicatat di Risiko).
- **D9 Navigasi**: grup `pondok.navigation.akademik`; ikon `ClipboardDocumentCheck` (absensi) dan `ChartBar` (rekap).

## Model data
- `attendance_sessions`: `id`, `classroom_id` FK `restrictOnDelete`, `held_on` date, `recorded_by` FK `users` nullable `nullOnDelete` (pencatat terakhir), timestamps. Unique (`classroom_id`, `held_on`); index (`held_on`).
- `attendance_records`: `id`, `attendance_session_id` FK `cascadeOnDelete`, `student_id` FK `cascadeOnDelete` (hanya force delete santri, seperti `health_visits`), `status` string(10), timestamps. Unique (`attendance_session_id`, `student_id`); index (`student_id`).
- Enum `App\Enums\AttendanceStatus`: `Present='hadir'`, `Sick='sakit'`, `Excused='izin'`, `Absent='alpa'`; `label()` dari `pondok.attendance.statuses.*`, `color()` success/warning/info/danger.
- Model `AttendanceSession` (cast `held_on` date; relasi `classroom`, `recorder`, `records`), `AttendanceRecord` (cast status enum; relasi `session`, `student` withTrashed). Relasi `Student::attendanceRecords()`. Factory keduanya. Tanpa soft delete.

## Action class
- `App\Actions\Absensi\BuildAttendanceSheet::handle(Classroom, CarbonInterface $date): Collection` (baca saja) — roster D4 urut nama; tiap baris: `student`, `status` (baris tersimpan atau `hadir`; `izin` bila terkunci), `leave` (izin `touchingDate` atau null, untuk petunjuk), `locked` (bool, `lockingDate`). Dipakai form dan Action simpan (DRY).
- `App\Actions\Absensi\RecordClassroomAttendance::handle(User $actor, Classroom $classroom, CarbonInterface|string $date, array<int,string> $statuses): AttendanceSession`
  - `authorize`: `$actor->can('create:attendance-session')` untuk sesi baru, `update:attendance-session` untuk sesi yang ada; rombel ∈ `visibleClassrooms($actor)` → selain itu `AuthorizationException` tanpa perubahan.
  - Validasi (`ValidationException`, pesan `pondok.attendance.errors.*`): D7; kunci `$statuses` harus santri roster; nilai harus `AttendanceStatus`.
  - Dalam `DB::transaction`: `firstOrCreate` sesi (`held_on`), set `recorded_by`; untuk tiap santri roster `updateOrCreate` baris: input bila ada, jika tidak ada pertahankan status tersimpan atau `hadir`; santri `locked` → `izin`. Baris santri di luar roster tidak disentuh. Bila insert sesi bentrok unique (dua pencatat bersamaan, `UniqueConstraintViolationException`), ulangi transaksi sekali lalu sesi yang ada dipakai.

## UI (Filament / Blade)
- `AttendanceSessionResource` (`BelongsToModule`, modul `absensi`, label `pondok.attendance.*`): List (kolom tanggal, rombel, jenjang, jumlah H/S/I/A lewat `withCount` bersyarat, pencatat; filter rombel; urut tanggal terbaru), Create (pilih rombel dari `visibleClassrooms` tahun ajaran aktif + tanggal bawaan hari ini → lembar roster dari `BuildAttendanceSheet`, `ToggleButtons` inline status per santri; bila sesi sudah ada, status tersimpan dimuat dan simpan = ubah), Edit (rombel & tanggal terkunci). Simpan memanggil `RecordClassroomAttendance`; galat Action tampil di form (`ShowsActionErrorsOnPageForm`). Tanpa aksi hapus.
- Page `AttendanceRecap` (`getModule()` = absensi, `canAccess` = modul aktif + `viewAny`): filter rombel (opsi `visibleClassrooms`) + bulan (`Y-m`, bawaan bulan ini) → tabel santri yang punya baris di bulan itu dengan kolom Hadir/Sakit/Izin/Alpa (`SUM(CASE ...)`, portabel). Query rekap selalu lewat `AttendanceSession` dengan scope `visible` (bukan join mentah), sehingga `classroom_id` kiriman langsung di luar `visibleClassrooms` menghasilkan tabel kosong; bulan disaring dengan rentang `held_on` (`>=` awal bulan, `<` awal bulan berikutnya), bukan fungsi tanggal khusus database.
- Portal wali `portal-wali/student.blade.php`: bagian "Kehadiran <bulan tahun>" bila `Module::Absensi` aktif — empat angka H/S/I/A bulan berjalan dan daftar hari tidak hadir (tanggal format §5 + badge status, urut tanggal). Data dari `User::portalAttendanceRecords(Student, month)` (tanpa unit/visible scope, pola `portalInvoices`); `ShowStudent` mengirim `null` saat modul mati. Keadaan kosong §7.
- Teks di `lang/id/pondok.php` (`attendance.*`, `modules.absensi.*`, `portal_wali.attendance.*`). design-guide §3 ikon, §4 status (sudah ditambah), checklist §0, cek 360/1024/desktop.

## Permission & cakupan
- `RESOURCE_MODELS` + `AttendanceSession`; `LIMITED_RESOURCE_ACTIONS['AttendanceSession'] = ['viewAny','view','create','update']`.
- Peran baru `wali-kelas` di `ROLES`: `view-dashboard` + keempat permission absensi (tanpa widget).
- `admin-sekolah`: keempatnya. `kesantrian`: `viewAny`, `view` (baca saja: memantau alpa santri untuk pembinaan). `super-admin`: semua. Peran lain: tidak ada.
- Seeder tetap memakai insert massal + sync pivot (tanpa `findOrCreate` per permission).

## Kriteria penerimaan (bisa dites)
- **AC-1 Modul.** `Module::Absensi->requires() === [Module::Akademik]`; `SettingSeeder` membuat saklar `true` (juga lewat `SystemSeeder`, bersama peran `wali-kelas`). Saklar mati → resource dan halaman rekap hilang dari navigasi dan 403, bagian kehadiran portal tidak tampil, baris absensi tetap ada; dashboard admin-sekolah dan wali-kelas tetap 200. `ModuleComplianceTest` lulus tanpa menambah daftar inti.
- **AC-2 Peran & permission.** `RolePermissionSeeder` membuat peran `wali-kelas` dengan persis `view-dashboard` + 4 permission absensi; kesantrian hanya `view-any`/`view`; admin-sekolah keempatnya. Bendahara, admin-konten, admin-psb, musyrif, ustadz-tahfidz, petugas-pos → 403 di index resource dan rekap.
- **AC-3 Catat baru.** Admin-sekolah, rombel 7A (3 santri aktif + 1 santri `lulus` + 1 santri rombel lain), tanggal hari ini, input satu `sakit` → 1 sesi (`recorded_by` = actor) dan 3 baris: satu `sakit`, dua `hadir`; santri lulus/rombel lain tanpa baris.
- **AC-4 Idempoten.** Menjalankan ulang sesi yang sama dengan input berbeda → tetap 1 sesi dan 1 baris per santri, status diperbarui; santri yang tidak ada di input mempertahankan status tersimpan; santri baru di-enroll setelah simpan pertama mendapat baris `hadir`; baris santri yang dipindah ke rombel lain tidak berubah. Dua rombel di tanggal sama = dua sesi.
- **AC-5 Validasi.** Tanggal besok, tanggal di luar tahun ajaran rombel, `student_id` di luar roster, atau status `terlambat` → `ValidationException`, tidak ada sesi/baris baru dan baris lama tidak berubah.
- **AC-6 Wali kelas.** User wali-kelas yang staff-nya wali 7A: bisa mencatat 7A; mencatat 7B → `AuthorizationException`; list dan rekap hanya menampilkan sesi/rombel 7A; buka edit sesi 7B → 404 (tersaring scope `visible`); rekap dengan `classroom_id` 7B kiriman langsung → tabel kosong. Wali-kelas tanpa baris `staff` atau bukan wali rombel mana pun melihat daftar kosong dan opsi rombel kosong.
- **AC-7 Kesantrian baca saja.** Kesantrian melihat list dan rekap dalam cakupan jenjangnya; halaman create/edit 403; `RecordClassroomAttendance` → `AuthorizationException`.
- **AC-8 Cakupan jenjang.** Admin-sekolah dengan `unit_user` SLTP tidak melihat sesi/rombel SLTA di list, opsi rombel, maupun rekap, dan mencatat rombel SLTA → `AuthorizationException`; admin-sekolah tanpa `unit_user` melihat keduanya.
- **AC-9 Izin dari Perizinan.** Perizinan aktif, izin keluar tanggal 10 dan kembali tanggal 13: tanggal 11 dan 12 → `izin` meski input `hadir`/`alpa`, juga saat actor wali-kelas, dan `locked` = true. Tanggal 10 (hari keluar), 13 (hari kembali), izin sehari (keluar dan kembali di hari yang sama), serta izin `disetujui` yang belum dicek keluar → input dipakai (bawaan `hadir`), `locked` = false, `leave` terisi sebagai petunjuk. Santri `keluar` sejak tanggal 10 yang lewat `return_due` tanggal 12 → tanggal 14 tetap `izin` terkunci. Izin `diajukan`/`ditolak`/`batal` → tidak ada `leave`, input dipakai. Perizinan mati → input dipakai, tanpa `leave`.
- **AC-10 Form Filament.** Create: pilih rombel + tanggal menampilkan roster dengan bawaan `hadir` (atau status tersimpan bila sesi ada); santri `locked` ditampilkan terkunci `izin`, santri dengan `leave` di hari batas menampilkan petunjuk jenis izin dan tetap bisa diubah; simpan membuat data AC-3 dan kembali ke list dengan notifikasi; tanggal masa depan menampilkan galat di field tanggal. Edit mengubah status. Badge status di list memakai `AttendanceStatus::color()`.
- **AC-11 Rekap bulanan.** Rombel 7A bulan Oktober dengan 3 sesi (termasuk 1 Oktober dan 31 Oktober) → tiap santri menampilkan jumlah H/S/I/A yang benar; sesi 30 September, 1 November, dan sesi rombel lain tidak terhitung.
- **AC-12 Portal wali.** Wali melihat di rincian anaknya jumlah H/S/I/A bulan berjalan dan daftar tanggal tidak hadir (urut tanggal, tanpa hari `hadir`, tanpa bulan lalu, tanpa data saudara/anak lain); `unit_user` tidak berpengaruh; santri bukan anaknya → 404; modul absensi mati → bagian tidak ada dan halaman tetap 200.
- **AC-13 Data demo.** `DemoSeeder` membuat akun `wali-kelas` yang terhubung ke staff wali SLTP 7A dan sesi absensi beberapa hari sekolah terakhir untuk 7A dan satu rombel SLTA, berisi campuran status, lewat `RecordClassroomAttendance` dengan aktor akun admin-sekolah demo; seed ulang tidak menggandakan sesi.

## Rencana test
| AC | File | Nama test (usulan) |
|---|---|---|
| 1, 13 | `tests/Feature/Absensi/AbsensiModuleTest.php` | `module requires akademik and is seeded on`, `disabling hides resource recap and portal section but keeps data`, `demo seeder creates wali kelas account and sessions idempotently` |
| 3, 4, 5, 9 | `tests/Feature/Absensi/RecordClassroomAttendanceTest.php` | `records new session for active roster`, `rerun updates without duplicates`, `rejects invalid date student or status`, `locks izin only for fully covered days`, `boundary days and one day leave stay editable with hint`, `approved leave not yet departed does not lock`, `overdue out leave keeps locking`, `ignores leave when perizinan disabled`, `retries once on concurrent session insert` |
| 2, 6, 7, 8 | `tests/Feature/Absensi/AbsensiAccessTest.php` | `roles get expected permissions`, `other roles are forbidden`, `wali kelas only sees and records homeroom`, `kesantrian is read only`, `unit scope limits sessions and classrooms`, `recap ignores classroom outside scope` |
| 10, 11 | `tests/Feature/Absensi/AttendanceSessionResourceTest.php` | `create page loads roster and saves`, `leave students are locked`, `future date shows form error`, `recap counts per student per month` |
| 12 | `tests/Feature/PortalWali/PortalWaliAttendanceTest.php` | `shows current month summary for own child`, `other child is 404`, `hidden when module disabled` |
Test lama yang daftar perannya berubah (`RolePermissionSeederTest`, peta peran di `tests/Pest.php`) diperbarui red-tester.

## Urutan implementasi
**Paket A — data, modul, Action, permission** (hijau: RecordClassroomAttendanceTest, AbsensiAccessTest bagian permission/Action, AbsensiModuleTest AC-1 bagian enum/seeder)
1. `Module::Absensi` + lang modul; enum `AttendanceStatus` + lang.
2. Migration 2 tabel, model, factory, relasi `Student`; `visibleClassrooms`, global scope `visible`.
3. `LeaveRequest::scopeLockingDate` dan `scopeTouchingDate`.
4. `RolePermissionSeeder`: model, peran `wali-kelas`, pembagian; policy `AttendanceSessionPolicy`.
5. `BuildAttendanceSheet`, `RecordClassroomAttendance` + lang galat.
**Paket B — Filament** (hijau: AttendanceSessionResourceTest, AbsensiAccessTest, AbsensiModuleTest AC-1, ModuleComplianceTest)
6. Resource (List/Create/Edit) + page rekap + view Blade rekap.
**Paket C — portal, demo, dokumen** (hijau: PortalWaliAttendanceTest, AbsensiModuleTest penuh, DemoSeederTest, test portal wali yang ada)
7. `User::portalAttendanceRecords`, `ShowStudent`, bagian Blade portal.
8. `DemoData` akun + staff `account => wali-kelas`; `Demo/DemoAttendance` setelah `DemoLeave`.
9. Cek tampilan §0 (design-guide §3 ikon dan §4 status sudah ditambah bersama rencana ini).

## Risiko & pertanyaan terbuka
- Sesi salah tanggal/rombel tidak bisa dihapus dari panel (D8); bila perlu, issue lanjutan "hapus sesi absensi (super-admin)".
- Wali kelas berganti di tengah tahun: wali lama langsung kehilangan akses ke sesi rombel itu (dihitung, bukan disimpan) — diterima.
- Izin yang dicek keluar/kembali setelah absensi disimpan tidak memperbarui baris lama (D5); pencatat menyimpan ulang sesi bila perlu.
- Instance yang memperbarui kode tanpa `pondok:upgrade` belum punya peran `wali-kelas`; catatan rilis menyebut langkah ini.
- Penambahan peran bawaan mengubah test yang mendaftar peran (lihat Rencana test).
- Tidak ada pertanyaan terbuka yang butuh keputusan user.

## Riwayat revisi
- 24 Okt 2026: DRAFT pertama.
- 24 Okt 2026: Revisi #1 atas Review #1 (keputusan orkestrator untuk T1/T2). Status → APPROVED oleh orkestrator tanpa putaran kedua.

## Review #1 — 2026-10-24

Verdict: CHANGES_REQUESTED (0 blocker, 1 major, 6 minor) — direvisi satu kali, lalu APPROVED oleh orkestrator.

| # | Tingkat | Bagian | Temuan | Revisi #1 |
|---|---|---|---|---|
| 1 | major | D5, AC-9, AC-10 | Cakupan izin per hari penuh mengunci hari keluar/kembali dan izin disetujui yang belum keluar. | D5 dipecah: `lockingDate` (kunci hanya hari yang sepenuhnya di luar: tanggal keluar < tanggal < tanggal kembali; izin `disetujui` belum keluar tidak mengunci) dan `touchingDate` (petunjuk saja). Sheet punya `locked`. AC-9/AC-10 dan test hari keluar, hari kembali, izin sehari, disetujui-belum-keluar. |
| 2 | minor | D5 | Santri `keluar` lewat `return_due` tidak tercakup. | Batas atas terbuka untuk status `keluar`: tetap `izin` terkunci sampai dicek kembali; kasus di AC-9. |
| 3 | minor | AttendanceRecap | Query rekap harus lewat scope `visible`/validasi `classroom_id`; filter bulan pakai rentang `held_on`. | Rekap lewat `AttendanceSession` ber-scope `visible`; `classroom_id` di luar cakupan → kosong; filter `>=` awal bulan dan `<` awal bulan berikut. AC-6, AC-11 (tanggal tepi) dan test diperbarui. |
| 4 | minor | D1, AC-1 | `SettingSeeder` `firstOrCreate` bernilai `true` → modul aktif di instance lama; peran baru butuh seeder saat upgrade. | D1 diperbaiki: `pondok:upgrade` menjalankan `SystemSeeder` (saklar + peran). Risiko: instance tanpa upgrade belum punya peran. |
| 5 | minor | D3 | `HasUnitScope` mengulang `visibleClassrooms`. | Cukup scope `visible`; `HasUnitScope` dihapus dari rencana. |
| 6 | minor | RecordClassroomAttendance | Tangani `UniqueConstraintViolationException`. | Ulangi transaksi sekali lalu pakai sesi yang ada; test `retries once on concurrent session insert`. |
| 7 | minor | AC-6, AC-13 | Tetapkan 404; aktor demo. | AC-6: edit sesi rombel lain → 404. AC-13: aktor demo = admin-sekolah demo. |
