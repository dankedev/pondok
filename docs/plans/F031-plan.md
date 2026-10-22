# F031 kesehatan — Kesehatan santri

Issue: #12
Jalur: penuh (data pribadi sensitif, migration, permission, Action yang mengubah data)
Status rencana: APPROVED

## Ringkasan & tujuan
Petugas mencatat kunjungan/keluhan kesehatan santri (UKS/poskestren): kapan, keluhannya, tindakan/obat, perlu rujukan, perlu izin pulang. Riwayat terlihat per santri dan di daftar dengan filter. Lingkup minimum sesuai komentar user di #12 (23 Okt 2026). Dibangun sebagai modul `kesehatan` (F066).

## Ruang lingkup
- Termasuk: modul + saklar, tabel `health_visits`, 3 Action, `HealthVisitResource`, tab riwayat di `StudentResource`, permission/peran, cakupan jenjang + cakupan musyrif, activity log, data demo, `lang/id`, ERD/PRD/design-guide.
- Tidak termasuk (usulan issue terpisah bila diminta user): stok obat, rekam medis lengkap (alergi, riwayat penyakit, golongan darah), integrasi izin pulang ke modul Perizinan (F024, sekarang cukup boolean), tampilan di portal wali, notifikasi wali/WhatsApp, widget dashboard, cetak surat rujukan, ekspor.

## Keputusan
- **D1 Enkripsi:** `complaint`, `treatment`, `note` memakai cast `encrypted` (kolom `text`). Pencarian teks tidak diwajibkan; filter hanya tanggal, jenjang, rujukan. Pencarian tabel hanya pada nama santri/NIS.
- **D2 Activity log manual di Action** (`activity()->inLog('kesehatan')`), bukan trait `LogsActivity`: properti hanya `student_id`, `visited_at`, flag boolean, dan untuk update `fields` = daftar **nama** kolom yang berubah. Isi keluhan/tindakan/catatan tidak pernah masuk log. `kesehatan` tidak ditambahkan ke `PruneActivityLog::PRUNABLE_LOG_NAMES`.
- **D3 Hapus:** hanya super-admin (permission `delete:health-visit` hanya lewat `allPermissions()`), satu per satu lewat `DeleteHealthVisit`; tanpa hapus massal (`deleteAny` = false di policy). Tanpa soft delete.
- **D4 Peran:** super-admin penuh; `kesantrian` lihat/catat/ubah; `musyrif` lihat/catat untuk santri binaannya (koreksi oleh kesantrian). Peran lain tanpa akses. Usulan (tidak dikerjakan): peran khusus `petugas-kesehatan`.
- **D5 Cakupan musyrif** memakai pola yang sudah ada (`RoomAssignment::scopeVisibleTo`): pemegang `view-any:student` melihat semua santri (dalam cakupan jenjang); selain itu hanya santri yang punya `room_assignments` aktif di kamar/asrama yang ia awasi. Satu sumber: `HealthVisit::visibleStudents(User): Builder<Student>` dipakai scope model, opsi form, dan Action. Konsekuensi: musyrif tidak lagi melihat catatan santri yang sudah pindah kamar (dicatat, diterima).
- **D6 Santri tidak bisa diganti saat edit.** Salah santri: super-admin hapus, lalu catat ulang. Catat baru hanya untuk santri `aktif`; edit tetap boleh untuk santri yang sudah lulus/keluar.
- **D7 Modul** `Kesehatan = 'kesehatan'`, `requires()` = `[Akademik]` (sama dengan Tahfidz/Prestasi). Asrama tidak diwajibkan; tanpa data penghuni, musyrif tidak melihat santri.

## Model data
Migration `create_health_visits_table` (rujuk ERD bagian baru "Kesehatan"):
- `id`; `student_id` FK `students` `cascadeOnDelete` (hanya terjadi saat force delete, sama dengan `memorization_records`)
- `visited_at` datetime (zona `Asia/Jakarta`); `complaint` text; `treatment` text nullable; `note` text nullable
- `needs_referral` boolean default false; `needs_leave` boolean default false
- `recorded_by` FK `users` nullable `nullOnDelete`; timestamps
- index (`student_id`, `visited_at`) dan (`visited_at`)
Model `HealthVisit`: `HasUnitScope` dengan `applyUnitScope` lewat `student_id` (pola `BelongsToHalaqah`), relasi `student()` `withTrashed`, `recorder()`, cast `visited_at` datetime, tiga kolom `encrypted`, boolean; `scopeVisibleTo(User)`; konstanta `TEXT_MAX_LENGTH = 1000`. Factory `HealthVisitFactory`. `Student::healthVisits()` hasMany.

## Action class (`app/Actions/Kesehatan/`)
- `RecordHealthVisit::handle(User $actor, array $data): HealthVisit` — validasi: `student_id` ada di `HealthVisit::visibleStudents($actor)` dan `aktif`; `visited_at` wajib, tidak lebih dari sekarang; `complaint` wajib ≤1000; `treatment`, `note` ≤1000; dua boolean. `recorded_by` = actor. Log `created`.
- `UpdateHealthVisit::handle(HealthVisit $visit, User $actor, array $data): HealthVisit` — visit harus terlihat oleh actor; validasi sama minus `student_id` (diabaikan bila dikirim); `recorded_by` tidak berubah. Log `updated` dengan `fields`, tanpa log bila tidak ada yang berubah.
- `DeleteHealthVisit::handle(HealthVisit $visit, User $actor): void` — `Gate::allows('delete', $visit)`, hapus, log `deleted`.
Galat sebagai `ValidationException` dengan teks `pondok.health_visit.errors.*`.

## UI (Filament)
- `HealthVisitResource` (`BelongsToModule`, `getModule()` = `kesehatan`), grup navigasi baru `pondok.navigation.kesehatan`, ikon `Heroicon::OutlinedHeart`. Halaman index, create, edit; `ViewAction` (modal) untuk yang tidak punya `update`. `getEloquentQuery()` memakai `visibleTo(auth()->user())`. Create/edit memanggil Action (pola `AchievementResource`, galat jadi notifikasi `danger`).
- Tabel: `visited_at` `->dateTime('j M Y, H.i')` urut terbaru, santri (nama + NIS, bisa dicari), jenjang, keluhan dipotong ±60 karakter, badge "Perlu rujukan" (`warning`) dan "Perlu izin pulang" (`warning`) hanya bila true, petugas. Filter: rentang tanggal, jenjang, `needs_referral` (ternary). Empty state dari `lang/id`.
- Form: santri (Select, opsi `visibleStudents` + aktif, nonaktif saat edit), tanggal-waktu (bawaan sekarang), keluhan, tindakan/obat, dua toggle, catatan.
- `Students\RelationManagers\HealthVisitsRelationManager` hanya baca: `canViewForRecord` = modul aktif dan `Gate::allows('viewAny', HealthVisit::class)`.
- Teks: `pondok.health_visit.*`, `pondok.modules.kesehatan.{label,description}`, `pondok.navigation.kesehatan`. Design-guide §4: tambah baris "Kesehatan | perlu rujukan, perlu izin pulang (penanda boolean) | warning"; format §5; state §7; konfirmasi hapus §8; alur §13.

## Permission & cakupan
- `RolePermissionSeeder::RESOURCE_MODELS` + `HealthVisit` (6 aksi standar). `kesantrian`: `viewAny, view, create, update`; `musyrif`: `viewAny, view, create`. `HealthVisitPolicy`: per record butuh permission + `isVisibleTo`; `deleteAny` false.
- Cakupan jenjang via global scope (`unit_user`); cakupan musyrif via D5.
- Saklar `modules.kesehatan.enabled`: `SettingSeeder` membuat `true` pada instalasi baru; `SystemSeeder`/upgrade tidak menimpa baris yang ada (pola F023 D5).

## Kriteria penerimaan
- **AC-1 Modul.** `Module::Kesehatan->requires()` = `[Akademik]`; label/deskripsi dari `lang/id`; seed instalasi membuat `modules.kesehatan.enabled = true`, seed ulang tidak menimpa `false`. `ModuleComplianceTest` tetap hijau dengan resource baru.
- **AC-2 Modul mati.** Saklar `false` → super-admin: `HealthVisitResource::canViewAny()` false, navigasi tidak tampil, URL index/create 403, tab kesehatan di halaman santri tidak tampil; jumlah baris `health_visits` tetap. Saklar dinyalakan → terlihat lagi.
- **AC-3 Enkripsi.** Nilai mentah `complaint`/`treatment`/`note` di DB (query builder) tidak sama dengan teks asli; lewat model terbaca utuh.
- **AC-4 Catat.** `RecordHealthVisit` oleh kesantrian → satu baris dengan `recorded_by` = actor, flag sesuai input (bawaan false); satu activity `log_name = kesehatan`, `event = created`, `subject` = visit, `causer` = actor, dan JSON properti tidak memuat teks keluhan/tindakan/catatan.
- **AC-5 Validasi catat.** Gagal (`ValidationException`, tanpa baris baru) bila: keluhan kosong atau >1000 karakter; `visited_at` kosong atau di masa depan; santri berstatus `lulus`/`keluar`; santri di luar cakupan actor.
- **AC-6 Ubah.** `UpdateHealthVisit` mengubah teks, waktu, dan flag; `student_id` dan `recorded_by` tetap walau dikirim lain; activity `updated` berisi `fields` nama kolom yang berubah tanpa isi teks; tanpa perubahan → tanpa activity baru. Edit catatan santri yang sudah `lulus` tetap boleh.
- **AC-7 Hapus.** Super-admin menghapus lewat `DeleteHealthVisit` → baris hilang, activity `deleted`. Kesantrian/musyrif: policy `delete` false, tombol hapus tidak ada, Action menolak. Bulk delete tidak tersedia untuk siapa pun.
- **AC-8 Akses per peran.** super-admin & kesantrian & musyrif: index 200. admin-sekolah, bendahara, admin-konten, admin-psb, ustadz-tahfidz, petugas-pos: `canViewAny` false dan index 403. Musyrif boleh create tetapi `update` false (URL edit 403, baris hanya punya `ViewAction`).
- **AC-9 Cakupan jenjang.** Kesantrian ber-`unit_user` SLTP hanya melihat catatan santri SLTP di daftar dan tab; edit catatan santri SLTA → 404; mencatat untuk santri SLTA ditolak. User tanpa `unit_user` melihat semua.
- **AC-10 Cakupan musyrif.** Musyrif yang mengawasi Kamar A hanya melihat catatan santri penghuni aktif Kamar A (bukan kamar lain, bukan nonmukim); opsi santri di form hanya penghuni itu; mencatat untuk santri di luar binaannya ditolak Action. Musyrif tanpa penugasan aktif melihat daftar kosong.
- **AC-11 Daftar & filter.** Urut `visited_at` terbaru; tanggal tampil `15 Okt 2026, 14.30`; filter rentang tanggal, jenjang, dan rujukan (ya/tidak) menyaring baris yang benar; pencarian nama santri bekerja; empty state berbahasa Indonesia.
- **AC-12 Form Filament.** Create lewat Livewire memanggil Action (baris dengan `recorded_by` = user login); galat Action tampil sebagai notifikasi/validasi tanpa baris baru; di edit, field santri disabled.
- **AC-13 Tab santri.** `HealthVisitsRelationManager` di edit santri (kesantrian) hanya baca dan hanya menampilkan catatan santri itu; tidak tampil untuk peran tanpa `view-any:health-visit`.
- **AC-14 Retensi log & demo.** `PRUNABLE_LOG_NAMES` tidak memuat `kesehatan`; activity `kesehatan` yang lebih tua dari masa simpan tidak terhapus `PruneActivityLog`. Seeder demo membuat 4–6 kunjungan lewat `RecordHealthVisit` (minimal satu perlu rujukan, satu perlu izin pulang, satu oleh akun demo musyrif untuk penghuni kamarnya), idempotent saat dijalankan dua kali.

## Rencana test
- `tests/Feature/Kesehatan/HealthModuleTest.php`: AC-1, AC-2, AC-14 (retensi). Perbarui hitungan saklar di `InstallSeederTest` dan `Tahfidz/TahfidzModuleTest` (9 → 10) bila menghitung jumlah modul.
- `tests/Feature/Kesehatan/HealthVisitActionsTest.php`: AC-3, AC-4, AC-5, AC-6, AC-7 (Action).
- `tests/Feature/Kesehatan/HealthVisitAccessTest.php`: AC-7 (policy/tombol), AC-8, AC-9, AC-10.
- `tests/Feature/Kesehatan/HealthVisitResourceTest.php`: AC-11, AC-12, AC-13.
- `tests/Feature/Database/Seeders/DemoHealthTest.php` (atau test demo yang ada): AC-14 demo.
Helper fixture bersama ditaruh di `tests/Pest.php` (`kesehatanFixture()`), tanpa memanggil seeder berat di `beforeEach` selain `RolePermissionSeeder`.

## Urutan implementasi
**Paket 1 — data, modul, Action** (hijau: HealthModuleTest, HealthVisitActionsTest)
1. Enum `Module::Kesehatan` + `requires()` + lang `modules.kesehatan.*`.
2. Migration, model `HealthVisit`, factory, relasi `Student::healthVisits()`.
3. Permission di `RolePermissionSeeder`, `HealthVisitPolicy`.
4. Action `RecordHealthVisit`, `UpdateHealthVisit`, `DeleteHealthVisit` + lang galat.
**Paket 2 — Filament** (hijau: HealthVisitAccessTest, HealthVisitResourceTest, ModuleComplianceTest)
5. `HealthVisitResource` (pages, form, table, filter), navigasi.
6. `HealthVisitsRelationManager` di `StudentResource`.
**Paket 3 — demo & dokumen** (hijau: test demo, InstallSeederTest)
7. `Demo/DemoHealth` dipanggil `DemoSeeder` setelah asrama.
8. ERD bagian "Kesehatan" + aturan bisnis; PRD §3.11 Kesehatan (Fase 4); design-guide §4.

## Risiko & pertanyaan terbuka
- Kolom terenkripsi tidak bisa dicari/diurutkan; bila user kelak minta pencarian keluhan, perlu desain lain (catat, bukan sekarang).
- `APP_KEY` berganti → teks kesehatan tidak terbaca (sama dengan `documents.number`); sudah tercakup panduan instalasi soal APP_KEY.
- Musyrif kehilangan visibilitas catatan lama setelah santri pindah kamar (D5) — diterima sebagai perilaku.
- Pertanyaan untuk user (tidak menghalangi): perlukah admin-sekolah melihat (lihat-saja) data kesehatan? Rencana ini: tidak, karena data sensitif.

## Riwayat revisi
- 23 Okt 2026: DRAFT pertama.

## Review #1 — 2026-10-23

Verdict: APPROVED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Rencana test / AC-14 | `PruneActivityLog::PRUNABLE_LOG_NAMES` adalah daftar putih (`['akademik','konten']`), jadi `kesehatan` otomatis tidak terhapus. Test "log lama tidak terhapus" hanya menguji konstanta. | Cukup satu assert singkat bahwa `kesehatan` tidak ada di konstanta; tak perlu skenario prune penuh. |
| 2 | minor | Rencana test | Catatan "hitungan saklar 9 → 10 di InstallSeederTest/TahfidzModuleTest" usang: test-test itu memakai `Module::cases()`, tidak ada angka keras. | Hapus catatan; cukup pastikan `SettingSeeder` memakai `Module::cases()`. |
| 3 | minor | D5 | Bypass cakupan musyrif memakai `view-any:student`, sedangkan `RoomAssignment::scopeVisibleTo` memakai `view-any:dormitory`. Peran kustom dengan izin kesehatan tanpa izin santri hanya melihat penghuni binaan. Aman (fail-closed), tetapi tidak seragam. | Pilih satu dan dokumentasikan di docblock `visibleStudents`. |
| 4 | minor | D2 / AC-4 | Log `created` memuat `needs_referral`/`needs_leave` + `student_id`, petunjuk status kesehatan. Dapat diterima karena tanpa teks. | Pastikan halaman Activity Log tetap hanya untuk super-admin. |
| 5 | minor | AC-2 | "URL index/create 403" bergantung pada perilaku `EnsureModuleIsEnabled`/`canViewAny`. | Samakan assert dengan test modul mati Tahfidz/Prestasi. |

Dicek dan sesuai: `requires()` Akademik sesuai pola Tahfidz/Prestasi; `cascadeOnDelete` pada `student_id` dapat diterima (santri hanya soft delete, sama dengan `memorization_records`; bukan data keuangan); tidak ada soft delete baru; teks sensitif `encrypted`; cakupan jenjang lewat `Student` mengikuti `BelongsToHalaqah`; tanpa fitur khusus DB; ±95 baris dengan 14 AC; YAGNI terjaga (stok obat, portal, widget dikeluarkan); AC bisa diassert di Pest dan mencakup jalur gagal, akses peran, dan jenjang.
