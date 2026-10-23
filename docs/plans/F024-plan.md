Issue: #5

# F024 perizinan — Perizinan, kepulangan & kunjungan

Jalur: penuh (migration, permission, Action yang mengubah data)
Status rencana: APPROVED

## Ringkasan & tujuan
Kesantrian/musyrif mencatat izin santri; izin otomatis diarahkan ke penyetuju sesuai `leave_types.approver` dan `dormitory_supervisors`; petugas pos mencatat keluar-kembali; pulang massal per periode libur; kunjungan wali (sambangan). Santri terlambat/belum kembali terlihat di daftar dan widget. Rujukan: PRD §3.9, ERD "Perizinan, Kepulangan & Kunjungan" + tabel index (±baris 994). Pola acuan: F031 (`docs/plans/F031-plan.md`), F023.

## Ruang lingkup
- Termasuk: modul `perizinan`, 4 tabel, Action, 4 resource Filament, 1 widget, permission/peran, cakupan jenjang + cakupan musyrif, activity log, jenis izin bawaan saat instalasi, data demo, `lang/id`, pembaruan ERD/PRD/design-guide.
- Tidak termasuk (usulan issue lanjutan bila user minta): pengajuan izin oleh wali di portal wali, notifikasi WhatsApp (F021 ditunda), QR/kartu/surat izin cetak, ubah izin yang sudah diajukan (cukup batal lalu ajukan ulang), hapus izin/kunjungan/jenis izin/periode, integrasi dengan Kesehatan (`needs_leave`), laporan/ekspor.

## Keputusan
- **D1 Modul** `Module::Perizinan = 'perizinan'`, `requires()` = `[Akademik, Asrama]` (penyetuju dari `dormitory_supervisors`, pulang massal dari `room_assignments`). Saklar dibuat `SettingSeeder` lewat `Module::cases()`, seed ulang tidak menimpa `false`.
- **D2 `approved_by` FK `users`** (bukan `staff` seperti ERD): semua kolom pelaku lain (`verified_by`, `fee_overrides.approved_by`) memakai `users`, dan kesantrian/super-admin yang menyetujui belum tentu punya baris `staff`. Kolom ini juga diisi saat ditolak (= pemutus). ERD diperbarui.
- **D3 Penyetuju dihitung, tidak disimpan.** Satu sumber: `App\Actions\Perizinan\ResolveLeaveApprover`:
  - `pendingFor(User $user): Builder<LeaveRequest>` — izin `diajukan` yang boleh diputus user. Pemegang `approve-any:leave-request` → semua izin `diajukan` yang terlihat (D5). Pemegang `approve:leave-request` → hanya bila ia (`staff.user_id`, `DormitorySupervisor::active()`) punya penugasan yang cocok dengan kamar aktif santri, dengan filter `dormitory_supervisors.role`: approver `musyrif` = `role = musyrif` dan (`room_id` = kamar santri **atau** `room_id` NULL + `dormitory_id` = asrama santri), keduanya sama-sama boleh (bukan fallback bertingkat); approver `kepala_asrama` = `role = kepala_asrama` dan `dormitory_id` = asrama santri. Approver `kesantrian`, santri nonmukim, atau tanpa penugasan yang cocok → hanya pemegang `approve-any` (kesantrian, super-admin).
  - `canDecide(User, LeaveRequest): bool` = `pendingFor($user)->whereKey($id)->exists()`. Dipakai policy, Action putuskan, dan filter "Menunggu persetujuan saya".
- **D4 Transisi status** hanya di Action: `diajukan → disetujui|ditolak|batal`, `disetujui → keluar|batal`, `keluar → kembali`. Selain itu `ValidationException` (`pondok.leave_request.errors.*`). Setiap transisi di `DB::transaction` dengan `lockForUpdate()` lalu cek ulang status (dua petugas yang menekan bersamaan tidak menghasilkan dua transisi).
- **D4a Otorisasi di Action.** Setiap Action (termasuk `SubmitLeaveRequest`, `SaveLeaveType`, `SaveHomecomingPeriod`, `GenerateHomecomingLeaves`, `RecordVisit`, `UpdateVisit`) memanggil `authorize($actor->can(...))` di awal seperti `RecordHealthVisit`; aktor tanpa permission → `AuthorizationException` tanpa perubahan data.
- **D5 Visibilitas izin** `LeaveRequest::scopeVisibleTo(User)` (global scope seperti `HealthVisit`, di atas cakupan jenjang): `view-any:student` → semua; `approve:leave-request` tanpa `view-any:student` (musyrif) → santri binaan; selain itu `check-in-out:leave-request` (petugas pos) → hanya status `disetujui`/`keluar` (pos tidak melihat izin `kembali`); tanpa ketiganya → fail-closed (`whereRaw('1 = 0')`). Logika "santri binaan" yang kini ada di `HealthVisit::visibleStudents` dipindah ke `Student::visibleStudentsFor(User): Builder` (pemakai kedua nyata); `HealthVisit::visibleStudents` tetap ada sebagai delegator karena dipakai `HealthVisitForm`, `RecordHealthVisit`, dan test Kesehatan.
- **D6 Terlambat dihitung**: `LeaveRequest::scopeLate()` = (`keluar` dan `return_due < now`) atau (`kembali` dan `returned_at > return_due`); accessor `is_late`. Tidak ada kolom/status.
- **D7 Pulang massal**: `GenerateHomecomingLeaves` membuat izin jenis `PULANG` berstatus `disetujui` (`approved_by` = actor, `requested_via` = `musyrif`, `reason` = nama periode, `planned_departure` = `depart_on` 00:00, `return_due` = `return_on` 23:59:59) untuk santri `aktif` dengan `room_assignments` aktif, **kecuali** santri yang masih punya izin `diajukan`/`disetujui`/`keluar` (dilewati dan dihitung; generate ulang mengikutkan mereka setelah izinnya selesai). Idempoten lewat unique (`student_id`, `homecoming_period_id`); izin biasa memakai `homecoming_period_id` NULL dan NULL tidak bentrok di unique (SQLite/MySQL/Postgres), jadi tanpa generated column. `requires_guardian` tidak dicek untuk pulang massal. Mengubah `return_on` periode memperbarui `return_due` izin periode itu yang masih `disetujui`/`keluar`.
- **D8 Widget** `OverdueLeavesWidget`: jumlah santri `keluar` yang lewat batas + 5 nama teratas + tautan ke daftar terfilter. Permission dashboard baru `view-leave-metrics`. Cukup sederhana (pola `RecentAchievementsWidget`).
- **D9 Activity log manual** `activity()->inLog('perizinan')` di Action: izin `created` + setiap transisi (`event` = status baru, properti `from`, `to`); kunjungan `created`/`updated` (`fields`). Tidak ditambah ke `PRUNABLE_LOG_NAMES`.
- **D11 `requested_via`** tetap enum ERD `wali | santri | musyrif` dan berarti asal permintaan, bukan siapa yang mengetik. Form admin menampilkan ketiganya (bawaan `musyrif`): kesantrian yang menerima telepon wali memilih `wali`. Portal wali/santri tidak dibangun di F024.
- **D10 Jenis izin bawaan** (`LeaveTypeSeeder` di `InstallSeeder`, `firstOrCreate` per `code`, tidak menimpa suntingan): KELUAR (6 jam, musyrif), PULANG (tanpa batas, kepala_asrama, wajib wali), SAKIT (kesantrian), KEPERLUAN (72 jam, kesantrian, wajib wali).

## Model data (migration satu file per tabel)
- `leave_types`: `code` string(20) unique, `name`, `max_hours` unsignedSmallInteger nullable, `approver` string (enum PHP `LeaveApprover`), `requires_guardian` bool, `is_active` bool default true, timestamps.
- `homecoming_periods`: `academic_year_id` FK `restrictOnDelete`, `name`, `depart_on` date, `return_on` date, timestamps.
- `leave_requests`: kolom ERD; `student_id` FK `cascadeOnDelete` (hanya force delete, pola `memorization_records`); `leave_type_id`, `homecoming_period_id` (nullable) FK `restrictOnDelete`; `requested_by`, `approved_by`, `checked_out_by`, `checked_in_by` FK `users` nullable `nullOnDelete`; `requested_via`, `status` string + enum PHP `LeaveRequestVia`, `LeaveRequestStatus` (method `label()`, `color()`); timestamps. Index (`status`, `return_due`), (`student_id`, `status`); unique (`student_id`, `homecoming_period_id`).
- `visits`: `student_id` FK `cascadeOnDelete`, `guardian_id` FK nullable `nullOnDelete`, `visitor_name`, `relation` string(50), `started_at`, `ended_at` nullable, `items_note` text nullable, `recorded_by` FK `users` nullable `nullOnDelete`, timestamps; index (`started_at`).
- Model `LeaveType`, `HomecomingPeriod`, `LeaveRequest` dan `Visit` (`HasUnitScope`, override `applyUnitScope` lewat `student_id` dengan `Student::withoutUnitScope()->withTrashed()` seperti `HealthVisit`). Konstanta `TEXT_MAX_LENGTH = 1000` untuk `reason`, `note`, `items_note`. Tanpa soft delete. Factory untuk keempatnya. `Student::leaveRequests()`, `Student::visits()`.

## Action class (`app/Actions/Perizinan/`)
- `ResolveLeaveApprover` (D3).
- `SubmitLeaveRequest::handle(User $actor, array $data)`: `reason` wajib ≤1000; santri ada di `Student::visibleStudentsFor($actor)` dan `aktif`; jenis aktif; `planned_departure < return_due`; durasi ≤ `max_hours` bila diisi; `pickup_name` wajib bila `requires_guardian`; tolak bila santri punya izin `diajukan`/`disetujui`/`keluar`. Status `diajukan`, `requested_by` = actor.
- `DecideLeaveRequest::handle(LeaveRequest, User, bool $approve, ?string $note)`: `canDecide`; set status, `approved_by`, `approved_at`, `note`.
- `CheckOutLeaveRequest` / `CheckInLeaveRequest::handle(LeaveRequest, User)`: butuh `check-in-out`; isi `departed_at`+`checked_out_by` / `returned_at`+`checked_in_by` = now/actor.
- `CancelLeaveRequest::handle(LeaveRequest, User, ?string $note)`: butuh `cancel` dan izin terlihat.
- `SaveLeaveType`, `SaveHomecomingPeriod` (validasi `depart_on ≤ return_on`; sinkron `return_due` D7), `GenerateHomecomingLeaves::handle(HomecomingPeriod, User): array{created: int, skipped: int}` (D7; galat bila jenis PULANG tidak ada/nonaktif; notifikasi Filament menampilkan kedua angka).
- `RecordVisit`, `UpdateVisit`: santri `aktif` dalam cakupan jenjang; `guardian_id` harus wali santri itu; `ended_at ≥ started_at`; `started_at` tidak di masa depan; santri tidak bisa diganti saat ubah.

## UI (Filament)
Grup navigasi `pondok.navigation.perizinan`, semua resource `BelongsToModule` = `perizinan`.
- `LeaveRequestResource` (ikon `ArrowRightStartOnRectangle`): index + create (form → `SubmitLeaveRequest`) + view modal. Kolom: santri (nama, NIS, dicari), jenjang, asrama (kamar aktif), jenis, rencana keluar, batas kembali, status badge, badge "Terlambat" (`danger`) bila `is_late`. Format tanggal `15 Okt 2026, 14.30`. Filter: status, jenis, asrama, periode pulang, "Terlambat", "Menunggu persetujuan saya". Aksi baris (tampil sesuai status + izin, memanggil Action, konfirmasi §8): Setujui, Tolak (catatan wajib), Catat keluar, Catat kembali, Batalkan.
- `LeaveTypeResource`, `HomecomingPeriodResource` (ikon `CalendarDays`; aksi "Buat izin pulang massal" → `GenerateHomecomingLeaves`, notifikasi jumlah), `VisitResource` (ikon baru, mis. `HandRaised`, didaftarkan di design-guide §3).
- Widget `OverdueLeavesWidget` (D8), `getDashboardModule()` = `perizinan`, `getRequiredTables()` = `['leave_requests']`.
- Design-guide §4 Perizinan: `diajukan` "menunggu" `warning`, `disetujui` `success`, `ditolak` `danger`, tambah `keluar` "di luar" `info`, `kembali` "sudah kembali" `success`, `batal` `gray`. Teks `pondok.leave_request.*`, `pondok.leave_type.*`, `pondok.homecoming_period.*`, `pondok.visit.*`, `pondok.modules.perizinan.*`, `pondok.dashboard.overdue_leaves.*`.

## Permission & cakupan
`RESOURCE_MODELS` + `LeaveType`, `HomecomingPeriod`, `LeaveRequest`, `Visit` dengan `LIMITED_RESOURCE_ACTIONS` (tanpa delete):
`LeaveType`: viewAny, view, create, update · `HomecomingPeriod`: + `generate` · `LeaveRequest`: viewAny, view, create, approve, approveAny, checkInOut, cancel · `Visit`: viewAny, view, create, update. `DASHBOARD_PERMISSIONS` + `view-leave-metrics`.
- super-admin: semua. kesantrian: semua permission di atas.
- musyrif: LeaveRequest viewAny, view, create, approve, cancel; `view-leave-metrics`.
- petugas-pos: LeaveRequest viewAny, view, checkInOut; Visit viewAny, view, create, update; `view-leave-metrics`.
Cakupan jenjang lewat `HasUnitScope`; cakupan musyrif/pos D5. Opsi santri di form kunjungan: santri aktif dalam cakupan jenjang (nama + NIS saja).

## Kriteria penerimaan
- **AC-1 Modul.** `Module::Perizinan->requires()` = `[Akademik, Asrama]`; label dari `lang/id`; seed instalasi membuat saklar `true`, seed ulang tidak menimpa `false`. Saklar `false` → keempat resource `canViewAny()` false dan URL 403 untuk super-admin, widget tidak tampil, jumlah baris tabel tetap. `ModuleComplianceTest` hijau.
- **AC-2 Jenis izin.** `InstallSeeder` membuat KELUAR/PULANG/SAKIT/KEPERLUAN sesuai D10; dijalankan dua kali tetap 4 baris dan `max_hours` yang disunting tidak tertimpa. Kesantrian bisa membuat/mengubah; policy `delete` false untuk semua peran. Jenis nonaktif tidak muncul di opsi dan ditolak `SubmitLeaveRequest`.
- **AC-3 Ajukan.** Kesantrian/musyrif mengajukan → baris `diajukan`, `requested_by` = actor, `requested_via` sesuai input; activity `perizinan`/`created`. Petugas pos/bendahara memanggil `SubmitLeaveRequest` → `AuthorizationException`, tanpa baris baru.
- **AC-4 Validasi ajukan.** `ValidationException` tanpa baris baru bila: `reason` kosong atau >1000 karakter; `return_due ≤ planned_departure`; durasi > `max_hours` (KELUAR 7 jam); PULANG tanpa `pickup_name`; santri `lulus`; santri di luar binaan musyrif atau di luar jenjang actor; santri masih punya izin `diajukan`/`disetujui`/`keluar`.
- **AC-5 Penyetuju otomatis.** `pendingFor`/`canDecide`: (a) KELUAR santri Kamar A → musyrif Kamar A ya, musyrif tingkat asrama (asrama yang sama) ya, musyrif kamar lain/asrama lain tidak; (b) KELUAR → kepala asrama santri **tidak**; (c) PULANG → kepala asrama ya, musyrif tidak; (d) SAKIT, santri nonmukim, atau penugasan sudah `end_on` → hanya kesantrian & super-admin; (e) kesantrian ber-`unit_user` SLTP tidak bisa memutus izin santri SLTA.
- **AC-6 Putuskan.** Setujui/tolak dari `diajukan` mengisi status, `approved_by`, `approved_at`, `note`; activity `from`/`to`. Ditolak bila actor bukan penyetuju (AC-5) atau status bukan `diajukan`.
- **AC-7 Pos.** Petugas pos: `disetujui → keluar` mengisi `departed_at`, `checked_out_by`; `keluar → kembali` mengisi `returned_at`, `checked_in_by`. Catat keluar dari `diajukan`/`kembali`, catat kembali dari `disetujui`, atau oleh musyrif → ditolak tanpa perubahan.
- **AC-8 Batal.** Dari `diajukan`/`disetujui` → `batal` dengan activity; dari `keluar`/`kembali`/`ditolak` → ditolak. Tidak ada aksi hapus izin untuk peran mana pun.
- **AC-9 Terlambat.** Dengan waktu dibekukan: `keluar` lewat `return_due` dan `kembali` dengan `returned_at > return_due` → `late()` memuat dan `is_late` true; `keluar` belum lewat batas dan `kembali` tepat waktu → tidak. Tabel `leave_requests` tidak punya kolom status terlambat.
- **AC-10 Pulang massal.** Generate untuk periode: hanya santri `aktif` dengan kamar aktif mendapat izin PULANG `disetujui` dengan `return_due` = `return_on` 23:59:59; nonmukim dan santri `lulus` tidak; santri yang sedang `keluar` dengan izin KELUAR dilewati (`skipped` = 1). Generate kedua menambah 0 baris; setelah santri tadi `kembali` dan santri baru masuk kamar, generate ketiga membuat tepat 2 baris. PULANG nonaktif → galat. Musyrif/petugas pos memanggil `GenerateHomecomingLeaves` atau `SaveHomecomingPeriod` → `AuthorizationException`, tanpa baris baru. Mengubah `return_on` memperbarui izin `disetujui`/`keluar`, bukan yang `kembali`.
- **AC-11 Kunjungan.** `RecordVisit` mengisi `recorded_by` + activity; `UpdateVisit` mengisi `ended_at` dan tidak mengganti santri. Ditolak bila `guardian_id` wali santri lain, `ended_at < started_at`, `started_at` di masa depan, atau santri di luar jenjang actor. Musyrif memanggil `RecordVisit`/`UpdateVisit` → `AuthorizationException`, tanpa perubahan data.
- **AC-12 Akses per peran.** super-admin, kesantrian: keempat index 200. musyrif: izin 200, jenis/periode/kunjungan 403. petugas-pos: izin & kunjungan 200, jenis/periode 403, dan daftar izin hanya `disetujui`/`keluar`. bendahara, admin-konten, admin-sekolah, admin-psb, ustadz-tahfidz: semua 403.
- **AC-13 Cakupan.** Musyrif Kamar A hanya melihat izin penghuni aktif kamarnya; kesantrian SLTP hanya izin & kunjungan santri SLTP (URL view SLTA 404). User tanpa permission izin mana pun → `LeaveRequest::query()` kosong (fail-closed). Izin santri yang di-soft-delete tetap terlihat oleh kesantrian. Test Kesehatan tetap hijau setelah refactor `Student::visibleStudentsFor`.
- **AC-14 Tabel & aksi Filament.** Filter "Terlambat", status, asrama, dan "Menunggu persetujuan saya" menyaring baris yang benar; tombol Setujui/Tolak hanya tampil bila `canDecide`, Catat keluar/kembali hanya pada status yang tepat untuk pemegang `check-in-out`; aksi lewat Livewire memanggil Action (status berubah, galat jadi notifikasi). Badge status memakai warna design-guide.
- **AC-15 Widget & demo.** `OverdueLeavesWidget` tampil untuk kesantrian/musyrif/petugas-pos, tidak untuk bendahara; menghitung hanya izin `keluar` lewat batas yang terlihat oleh user; render "Modul belum tersedia"/tersembunyi saat modul mati. Demo: periode "Libur Idul Fitri 1448 H" + izin berbagai status (minimal satu terlambat) + 2–3 kunjungan, idempoten saat dijalankan dua kali.

## Rencana test
- `tests/Feature/Perizinan/PerizinanModuleTest.php`: AC-1, AC-2, AC-15 (demo).
- `tests/Feature/Perizinan/LeaveRequestActionsTest.php`: AC-3, AC-4, AC-6, AC-7, AC-8, AC-9.
- `tests/Feature/Perizinan/ResolveLeaveApproverTest.php`: AC-5.
- `tests/Feature/Perizinan/HomecomingTest.php`: AC-10.
- `tests/Feature/Perizinan/VisitActionsTest.php`: AC-11.
- `tests/Feature/Perizinan/PerizinanAccessTest.php`: AC-12, AC-13.
- `tests/Feature/Perizinan/LeaveRequestResourceTest.php`: AC-14, AC-15 (widget).
Fixture bersama `perizinanFixture()` di `tests/Pest.php` (asrama, kamar, musyrif kamar/asrama, kepala asrama, santri mukim/nonmukim, jenis bawaan); hanya `RolePermissionSeeder` di `beforeEach`.

## Urutan implementasi
**Paket A — data, modul, Action, permission** (hijau: PerizinanModuleTest kecuali demo, LeaveRequestActionsTest, ResolveLeaveApproverTest, HomecomingTest, VisitActionsTest, test Kesehatan)
1. Enum `Module::Perizinan` + lang; enum `LeaveApprover`, `LeaveRequestStatus`, `LeaveRequestVia`.
2. Migration 4 tabel, model, factory, relasi `Student`; `LeaveTypeSeeder` di `InstallSeeder`.
3. Refactor `Student::visibleStudentsFor` (+ delegator `HealthVisit::visibleStudents`); scope `visibleTo`/`late` di `LeaveRequest`.
4. Permission di `RolePermissionSeeder`, policy keempat model.
5. Action (urutan bagian Action) + lang galat.
**Paket B — Filament** (hijau: PerizinanAccessTest, LeaveRequestResourceTest, ModuleComplianceTest)
6. Empat resource + aksi baris + filter; widget.
**Paket C — demo, lang, dokumen** (hijau: PerizinanModuleTest penuh, test demo/instalasi yang ada)
7. `Demo/DemoLeave` dipanggil `DemoSeeder` setelah asrama.
8. ERD (D2, catatan NULL unique, visits index), PRD §3.9 (lingkup + yang tidak termasuk), design-guide §3 ikon + §4 status.

## Risiko & pertanyaan terbuka
- Pergantian musyrif saat izin masih `diajukan` memindahkan hak putus ke musyrif baru (karena dihitung) — diterima, sesuai "diarahkan otomatis".
- Penyetuju dari dua tingkat (kamar dan asrama) bisa memutus izin yang sama; penguncian D4 mencegah keputusan ganda.
- Santri yang pindah kamar setelah izin dibuat: musyrif lama tidak lagi melihatnya (sama dengan F031 D5).
- Tidak ada pertanyaan terbuka.

## Riwayat revisi
- 23 Okt 2026: DRAFT pertama.
- 23 Okt 2026: revisi Review #1. D3/AC-5 penyetuju musyrif kamar atau asrama tanpa fallback bertingkat + kasus kepala asrama untuk KELUAR (temuan 1); D4a otorisasi di semua Action + kasus tanpa permission di AC-3/10/11 (2); pulang massal melewati santri berizin aktif + `skipped` (3); fail-closed (4); transaksi + `lockForUpdate` (5); `reason` wajib ≤1000 (6); `applyUnitScope` withTrashed (7); D11 `requested_via` (8, form tetap menampilkan tiga nilai ERD sebagai asal permintaan); nama `Student::visibleStudentsFor` + delegator (9). Keputusan orkestrator: pos tidak melihat izin `kembali`. Status → APPROVED atas persetujuan orkestrator.

## Review #1 — 2026-10-23

Verdict: CHANGES_REQUESTED (0 blocker, 3 major, 6 minor) — direvisi satu kali, lalu APPROVED oleh orkestrator.

| # | Tingkat | Bagian | Temuan | Penyelesaian |
|---|---|---|---|---|
| 1 | major | D3, AC-5, Risiko | Penyetuju musyrif dengan fallback bertingkat terlalu rumit; kasus kepala asrama untuk jenis KELUAR belum diuji. | D3: musyrif kamar atau musyrif tingkat asrama (filter `dormitory_supervisors.role`), keduanya boleh; AC-5(b) kepala asrama tidak untuk KELUAR; kalimat fallback di Risiko dihapus. |
| 2 | major | Action, AC-3/10/11 | Tidak semua Action mengecek policy/permission di awal seperti `RecordHealthVisit`. | D4a; kasus "aktor tanpa permission ditolak tanpa perubahan data" di AC-3, AC-10, AC-11. |
| 3 | major | D7, AC-10 | Pulang massal bisa menimpa santri yang sedang punya izin aktif. | Santri berizin `diajukan`/`disetujui`/`keluar` dilewati, `skipped` dilaporkan, ikut pada generate ulang; diassert di AC-10. |
| 4 | minor | D5 | `scopeVisibleTo` harus fail-closed bagi user tanpa ketiga permission. | `whereRaw('1 = 0')`; assert di AC-13. |
| 5 | minor | D4 | Transisi status rawan balapan. | `DB::transaction` + `lockForUpdate()` dan cek ulang status. |
| 6 | minor | Action | Batas `reason` belum ditetapkan. | Wajib, maks 1000 (`TEXT_MAX_LENGTH`); AC-4. |
| 7 | minor | Model data | `applyUnitScope` perlu meniru `HealthVisit` (santri arsip). | Override dengan `withTrashed`; assert di AC-13. |
| 8 | minor | `requested_via` | Nilai enum dan pemakaian di form belum jelas. | D11: enum ERD tetap; berarti asal permintaan, form menampilkan tiga nilai (bawaan `musyrif`). Menyimpang dari saran reviewer (form tanpa wali/santri) atas pertimbangan orkestrator: kesantrian sering mencatat permintaan wali lewat telepon. |
| 9 | minor | D5 | Nama helper `Student::visibleTo` bentrok makna dengan scope lain. | `Student::visibleStudentsFor(User)`; `HealthVisit::visibleStudents` tetap sebagai delegator (dipakai form, Action, test Kesehatan). |
