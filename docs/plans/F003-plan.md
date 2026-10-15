# F003 asrama — Asrama, kamar & musyrif

Status rencana: APPROVED

Owner: tim-agent-fase1. Dependensi: F002 (`done`, commit f4e1fbf).

## Ringkasan & tujuan

Modul Asrama mencatat asrama (putra/putri), kamar dengan kapasitas, penempatan santri ke kamar beserta riwayat pindah kamar, dan penugasan musyrif/kepala asrama per tahun ajaran. Status mukim santri tidak disimpan sebagai kolom, melainkan diturunkan dari `room_assignments` yang `end_on`-nya kosong. Fitur ini menjadi dasar F004/F006 (tarif per asrama, biaya `applies_to = mukim`) dan F024 (approver izin dari musyrif).

Acuan: PRD 2 (peran Kesantrian, Musyrif), PRD 3.3, ERD bagian "Asrama & Musyrif" dan tabel constraint (`room_assignments`, `rooms`).

## Ruang lingkup

- Termasuk:
  - Migration, model, factory untuk `dormitories`, `rooms`, `room_assignments`, `dormitory_supervisors`.
  - Action `AssignRoom` (tempatkan/pindah kamar), `VacateRoom` (keluar asrama, jadi nonmukim), `AssignSupervisor`, `EndSupervision`, `SaveRoom` (buat/ubah kamar dengan aturan kapasitas).
  - Relasi dan helper mukim pada `Student`: `roomAssignments()`, `activeRoomAssignment()`, `activeRoom()`, `isResident()`, scope `resident()` / `nonResident()`.
  - Panel Filament: resource Asrama (dengan relation manager Kamar dan Musyrif) dan resource Penghuni asrama (penempatan, pindah, keluar, riwayat).
  - Kolom "Kamar" (atau "Nonmukim") di tabel santri saat modul asrama aktif.
  - Cakupan jenjang (`unit_user`) pada `RoomAssignment` lewat `HasUnitScope`.
  - Musyrif (Fase 1): daftar penghuni lihat-saja, terbatas pada asrama/kamar yang ia awasi di tahun ajaran aktif.
  - Permission baru, policy Shield, saklar `modules.asrama.enabled` (aktif bawaan), teks `lang/id/pondok.php`.
- Tidak termasuk (eksplisit):
  - Persetujuan izin oleh musyrif, pulang massal, rekap belum kembali (F024 Perizinan).
  - Tarif per asrama dan penagihan biaya mukim (F004/F006). F003 hanya menyediakan `Student::activeRoom()` dan scope mukim.
  - Widget dashboard asrama (F018).
  - Menyalin penugasan musyrif dari tahun ajaran sebelumnya (usulan entri baru, lihat Risiko).
  - Penempatan massal (banyak santri sekaligus) dan pengosongan otomatis saat santri lulus/keluar (lihat Pertanyaan terbuka).
  - Halaman/portal khusus musyrif di luar panel admin; musyrif memakai panel admin dengan cakupan terbatas.
  - Mengedit atau menghapus baris riwayat `room_assignments` / `dormitory_supervisors` dari UI.

## Model data

Semua tabel memakai `timestamps`. Tidak ada soft delete (soft delete hanya `students`, `applicants`, `posts`). Bukan data keuangan, tetapi FK ke master asrama memakai `restrictOnDelete` agar riwayat tidak hilang.

### `dormitories` — model `App\Models\Dormitory`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| code | string(20), unique | contoh `ABK-1` |
| name | string | contoh "Asrama Abu Bakar" |
| gender | enum `L`, `P` | wajib; tidak ada `campur` (PRD 3.3: putra/putri) |
| location | string, nullable | gedung/blok |
| is_active | boolean, default true | |

Cast: `is_active` boolean. Konstanta `GENDERS = ['L', 'P']`. Relasi: `rooms()` HasMany, `supervisors()` HasMany(`DormitorySupervisor`).

### `rooms` — model `App\Models\Room`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| dormitory_id | foreignId → dormitories, `restrictOnDelete` | |
| name | string(50) | "Kamar 101" |
| floor | unsignedTinyInteger, nullable | |
| capacity | unsignedSmallInteger | minimal 1 (divalidasi di form) |
| is_active | boolean, default true | |

Unique (`dormitory_id`, `name`). Cast: `floor`, `capacity` integer, `is_active` boolean. Relasi: `dormitory()`, `assignments()` HasMany, `activeAssignments()` HasMany `whereNull('end_on')`. Method `occupiedCount(): int` dan `remainingCapacity(): int` yang menghitung **tanpa** cakupan jenjang (`RoomAssignment::withoutUnitScope()`), karena kapasitas fisik kamar berlaku untuk semua jenjang.

### `room_assignments` — model `App\Models\RoomAssignment`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| student_id | foreignId → students, `cascadeOnDelete` | mengikuti pola `enrollments`; santri hanya hard-delete pada kasus luar biasa |
| room_id | foreignId → rooms, `restrictOnDelete` | |
| academic_year_id | foreignId → academic_years, `restrictOnDelete` | tahun ajaran aktif saat penempatan dibuat (informasi saja) |
| start_on | date | |
| end_on | date, nullable | null = masih menempati |
| note | string, nullable | alasan pindah/keluar |
| active_marker | unsignedTinyInteger, nullable, generated **stored** `CASE WHEN end_on IS NULL THEN 1 END` | 1 = aktif, NULL = riwayat. Tidak ada di `$fillable`, tidak pernah ditulis aplikasi |

Index (`student_id`, `end_on`) dan (`room_id`, `end_on`) sesuai ERD. Unique (`student_id`, `active_marker`): menjamin satu penempatan aktif per santri di level database, sementara baris riwayat (NULL) boleh banyak karena NULL dianggap berbeda di SQLite, MySQL/MariaDB, dan Postgres. Migration memakai `->storedAs('CASE WHEN end_on IS NULL THEN 1 END')` (pola generated column `fee_rates`); baris ini sudah ditambahkan ke tabel constraint `docs/erd.md`. Cast `start_on`, `end_on` date. Trait `HasUnitScope` dengan override `applyUnitScope()` yang menyaring lewat `students.unit_id` (subquery `Student::withoutUnitScope()->withTrashed()`). Scope `active()` = `whereNull('end_on')`. Scope `visibleTo(User $user)` untuk musyrif (lihat Permission & cakupan).

Aturan: penempatan aktif **tidak** berakhir karena pergantian tahun ajaran; aktif murni berarti `end_on IS NULL`. Satu santri maksimal satu baris aktif, dijaga dua lapis: (a) unique `active_marker` di database, (b) Action `AssignRoom` mengunci baris induk `Room` dan `Student` dalam transaksi sebelum memeriksa (lihat Action). Tidak ada `lockForUpdate` pada query agregat (Postgres menolak `FOR UPDATE` dengan agregat).

### `dormitory_supervisors` — model `App\Models\DormitorySupervisor`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| dormitory_id | foreignId → dormitories, `restrictOnDelete` | |
| room_id | foreignId → rooms, nullable, `restrictOnDelete` | null = seluruh asrama |
| staff_id | foreignId → staff, `restrictOnDelete` | |
| academic_year_id | foreignId → academic_years, `restrictOnDelete` | |
| role | enum `kepala_asrama`, `musyrif` | |
| start_on | date | |
| end_on | date, nullable | null = masih bertugas |

Index (`staff_id`, `end_on`) dan (`dormitory_id`, `academic_year_id`). Konstanta `ROLE_HEAD = 'kepala_asrama'`, `ROLE_SUPERVISOR = 'musyrif'`, `ROLES`. Scope `active()` = `end_on IS NULL` **dan** `academic_year_id` = tahun ajaran aktif (penugasan berlaku per tahun ajaran, PRD 3.3).

### Tambahan pada model yang sudah ada
- `Student`: `roomAssignments()` HasMany; `activeRoomAssignment()` HasOne `whereNull('end_on')`; `activeRoom()` HasOneThrough(`Room`, `RoomAssignment`, `student_id`, `id`, `id`, `room_id`) dengan `whereNull('room_assignments.end_on')` sehingga `$student->activeRoom?->dormitory_id` (pseudocode ERD generator) langsung bisa dipakai F006; `isResident(): bool`; scope `resident()` (`whereHas` penempatan aktif) dan `nonResident()` (`whereDoesntHave`).
- `Staff`: `supervisions()` HasMany(`DormitorySupervisor`).
- `AcademicYearFactory`: state `active()` (`is_active = true`) untuk test.
- Factory baru: `DormitoryFactory` (state `male()`/`female()`), `RoomFactory` (capacity default 10, `for` dormitory), `RoomAssignmentFactory` (state `ended()`), `DormitorySupervisorFactory` (state `head()`, `forRoom(Room)`, `ended()`).

## Action class

Semua Action di `app/Actions/Asrama/`. Kegagalan melempar `ValidationException` dengan pesan `pondok.asrama.errors.<reason>` (pola `EnrollStudent`). Tanggal bawaan = `today()` (zona `Asia/Jakarta`).

### `AssignRoom::handle(Student $student, Room $room, ?CarbonInterface $startOn = null, ?string $note = null): RoomAssignment`
Menempatkan santri ke kamar; bila santri sudah mukim di kamar lain, ini adalah pindah kamar.
Urutan validasi (field error `room_id`):
1. Santri berstatus `aktif` → selain itu `student_inactive`.
2. `room.is_active` → `room_inactive`; `room.dormitory.is_active` → `dormitory_inactive`.
3. `room.dormitory.gender === student.gender` → `gender_mismatch`.
4. Ada tahun ajaran aktif → `no_active_academic_year`.
Dalam `DB::transaction`:
5. Kunci baris induk: `Student::withoutUnitScope()->withTrashed()->lockForUpdate()->findOrFail($student->id)` (serialisasi per santri) dan `Room::query()->lockForUpdate()->findOrFail($room->id)` (serialisasi per kamar; nilai `capacity`/`is_active` dibaca ulang dari baris terkunci). Urutan kunci selalu santri lalu kamar agar tidak deadlock. Di SQLite kunci ini no-op, tetapi SQLite menserialkan penulisan dan unique `active_marker` tetap menjadi pengaman terakhir.
6. Ambil penempatan aktif santri dengan query biasa (`RoomAssignment::withoutUnitScope()`, tanpa `lockForUpdate`). Jika `room_id` sama → kembalikan baris itu tanpa perubahan (idempotent, tidak ada baris baru).
7. Hitung penghuni aktif kamar tujuan dengan `count()` biasa (`withoutUnitScope`); jika `>= capacity` → `room_full`.
8. Jika ada penempatan aktif di kamar lain: `startOn` harus `>=` `start_on` lama → selain itu `start_before_current`; tutup baris lama dengan `end_on = startOn` dan `note` lama tidak diubah. Baris lama wajib disimpan (ditutup) **sebelum** baris baru dibuat agar unique `active_marker` tidak bentrok.
9. Buat baris baru (`student_id`, `room_id`, `academic_year_id` = tahun aktif, `start_on`, `note`).
Efek samping: tidak ada selain baris di atas. Kegagalan di langkah mana pun tidak mengubah baris lama.

### `VacateRoom::handle(Student $student, ?CarbonInterface $endOn = null, ?string $note = null): RoomAssignment`
Mengakhiri penempatan aktif; santri menjadi nonmukim.
- Tidak ada penempatan aktif → `not_resident`.
- `endOn < start_on` → `end_before_start`.
- Set `end_on = endOn`; `note` diisi bila diberikan (menimpa catatan baris itu hanya bila argumen tidak null).
- Kembalikan baris yang ditutup.

### `AssignSupervisor::handle(Dormitory $dormitory, Staff $staff, string $role, AcademicYear $academicYear, ?Room $room = null, ?CarbonInterface $startOn = null): DormitorySupervisor`
Field error `staff_id` / `room_id`.
- `role` di luar `ROLES` → `invalid_role`.
- `staff.is_active` false → `staff_inactive`.
- `room` diberikan tetapi `room.dormitory_id !== dormitory.id` → `room_dormitory_mismatch`.
- `role = kepala_asrama` dengan `room` tidak null → `head_requires_dormitory` (kepala asrama selalu untuk seluruh asrama).
- Idempotent: penugasan aktif (`end_on` null) dengan staff, asrama, kamar (null-aman), peran, dan tahun ajaran sama → kembalikan yang ada.
- `kepala_asrama`: kepala asrama lain yang masih aktif (`end_on` null) di asrama dan tahun ajaran yang sama ditutup dengan `end_on = startOn` (pergantian tercatat). Bila `startOn` lebih awal dari `start_on` kepala lama → gagal `start_before_current_head` dan kepala lama tidak berubah (konsisten dengan `start_before_current` di `AssignRoom`, mencegah `end_on < start_on`). Semua dalam transaksi.
- `musyrif`: boleh lebih dari satu per asrama atau per kamar; tidak menutup musyrif lain.

### `SaveRoom::handle(Dormitory $dormitory, array $data, ?Room $room = null): Room`
Membuat (`$room` null) atau mengubah kamar. Dipanggil dari `RoomsRelationManager` (create dan edit memakai `->using(...)`), sehingga aturan kapasitas berlaku di luar form juga. `$data` berisi `name`, `floor`, `capacity`, `is_active`. Field error sesuai nama kolom.
- `capacity < 1` → `capacity_min`.
- Edit: `capacity` lebih kecil dari `$room->occupiedCount()` (penghuni aktif semua jenjang) → `capacity_below_occupancy`.
- Edit: `is_active` diubah ke false padahal `occupiedCount() > 0` → `room_has_residents` (keputusan: **ditolak**; penghuni harus dipindah atau dikeluarkan dulu agar tidak ada santri mukim di kamar nonaktif).
- Kamar baru selalu milik `$dormitory`; mengubah `dormitory_id` kamar tidak didukung.
- Keunikan nama per asrama tetap lewat rule form `unique` + constraint DB.
Catatan: asrama yang dinonaktifkan hanya menghalangi penempatan baru (`dormitory_inactive`); penghuni lamanya tidak diubah. Ini dicatat sebagai perilaku, bukan divalidasi di F003.

### `EndSupervision::handle(DormitorySupervisor $supervisor, ?CarbonInterface $endOn = null): DormitorySupervisor`
- Sudah berakhir (`end_on` tidak null) → `supervision_already_ended`.
- `endOn < start_on` → `end_before_start`.
- Set `end_on`.

## UI (Filament / Blade)

Rujukan design-guide: §3 (ikon `HomeModern` untuk asrama/kamar, `ArrowsRightLeft` untuk pindah kamar — keduanya sudah ada di tabel), §5 (tanggal `j M Y`, kosong `-`, gender), §6 (struktur resource, tabel ≤7 kolom, filter, aksi bisnis sebagai static method resource), §7 (empty state, notifikasi gagal/berhasil), §8 (konfirmasi untuk aksi keluar asrama dan akhiri tugas; label kata kerja + objek), §9 (istilah musyrif, mukim, nonmukim apa adanya). Tidak ada status baru, jadi tabel §4 tidak berubah. Implementer wajib memanggil `/anti-ui-slop` bila tersedia dan menjalankan checklist §0.

Grup navigasi baru `pondok.navigation.asrama` = "Asrama". Semua resource memakai `BelongsToModule` dengan `getModule(): 'asrama'`.

### `DormitoryResource` (`app/Filament/Resources/Dormitories/`)
Struktur `Resource` + `Schemas/DormitoryForm` + `Tables/DormitoriesTable` + `RelationManagers`. Halaman List/Create/Edit. Ikon `HomeModern`, navigationSort 2.
- Form (Section `section_identity`, 2 kolom): `code` (unique), `name`, `gender` (opsi `pondok.dormitory.genders`: L = Putra, P = Putri), `location`, `is_active`.
- Tabel: `code`, `name`, `gender` (label), `rooms_count`, penghuni aktif ("terisi / kapasitas total"), `location` (toggleable tersembunyi), `is_active` (IconColumn, pola `StaffTable`). `defaultSort('name')`. Filter `gender`, `is_active` (TernaryFilter). Hapus hanya tampil bila `rooms_count = 0` **dan** `supervisors_count = 0` (semua FK ke asrama `restrictOnDelete`, jadi riwayat musyrif juga menahan penghapusan).
- `RoomsRelationManager`: kolom `name`, `floor` (placeholder `-`), "terisi / kapasitas", `is_active`. Create dan Edit memanggil Action `SaveRoom`; `ValidationException` dari Action ditampilkan sebagai error field/notifikasi `danger` tanpa menyimpan. `name` unik per asrama (`pondok.room.unique`). Delete hanya tampil bila kamar belum pernah punya baris `room_assignments` **dan** tidak punya baris `dormitory_supervisors`.
- `SupervisorsRelationManager`: kolom staff, peran (label `pondok.dormitory_supervisor.roles`), kamar (placeholder `pondok.dormitory_supervisor.whole_dormitory` = "Seluruh asrama"), tahun ajaran, mulai, selesai (placeholder `-`). Filter tahun ajaran default tahun aktif. Header action `Tambah musyrif` (`DormitoryResource::assignSupervisorAction()`) dengan field staff (staff aktif, searchable), peran, kamar (opsional, hanya kamar asrama ini; disembunyikan bila peran kepala asrama), tahun ajaran (default aktif), tanggal mulai (default hari ini) → `AssignSupervisor`. Aksi baris `Akhiri tugas` (danger, `requiresConfirmation`, deskripsi akibat, field tanggal selesai) → `EndSupervision`, tampil hanya untuk baris aktif. Tanpa Edit/Delete.

### `RoomAssignmentResource` — "Penghuni asrama" (`app/Filament/Resources/RoomAssignments/`)
Halaman tunggal `ManageRoomAssignments` (pola `Manage…`). Ikon `HomeModern`, navigationSort 1 (paling sering dipakai).
- `getEloquentQuery()`: query bawaan (sudah tersaring `HasUnitScope`) + `visibleTo(auth()->user())`.
- Tabel: `student.nis`, `student.name`, `student.unit.name`, `room.dormitory.name`, `room.name`, `start_on` (`j M Y`), `end_on` (toggleable tersembunyi, placeholder `-`), `note` (toggleable tersembunyi). Eager-load `student.unit`, `room.dormitory`. `defaultSort` asrama → kamar → nama santri.
- Filter: `status` (SelectFilter `aktif` / `riwayat` / semua, default `aktif`), asrama, jenjang (`Unit::query()->accessible()`).
- Header action `Tempatkan santri` (primary, `RoomAssignmentResource::assignAction()`): pilih santri (searchable; hanya santri aktif tanpa penempatan aktif, otomatis tersaring cakupan jenjang), asrama (hanya asrama aktif dengan gender santri), kamar (kamar aktif di asrama itu, label menyebut sisa tempat, misalnya "Kamar 101 (3 tempat kosong)"), tanggal masuk (default hari ini), catatan → `AssignRoom`.
- Aksi baris (dalam `ActionGroup`, hanya untuk baris aktif): `Pindah kamar` (ikon `ArrowsRightLeft`; asrama, kamar, tanggal pindah, alasan) → `AssignRoom`; `Keluarkan dari asrama` (danger, `requiresConfirmation`, deskripsi: santri menjadi nonmukim dan riwayat tetap tersimpan; tanggal keluar, alasan) → `VacateRoom`.
- Semua `ValidationException` dari Action ditangkap dan ditampilkan sebagai notifikasi `danger`, lalu `$action->halt()` (pola `ClassroomResource::promoteAction`). Berhasil → notifikasi `success` yang menyebut santri dan kamar.
- Empty state: `pondok.room_assignment.empty` = "Belum ada santri yang ditempatkan di asrama."
- Tidak ada Edit/Delete.

### Tabel santri (`StudentsTable`)
Tambah kolom `pondok.student.room` = "Kamar" berisi "Nama asrama — Nama kamar" dari `activeRoom.dormitory`, placeholder `pondok.room_assignment.non_resident` = "Nonmukim". Kolom hanya `visible` bila `Setting::moduleEnabled('asrama')`. Eager-load `activeRoom.dormitory` hanya bila modul asrama aktif (di `modifyQueryUsing`, bersyarat), sehingga saat modul mati tidak ada query ke tabel asrama dari tabel santri. Kolom ini menggantikan slot toggleable agar jumlah kolom tampil tetap ≤7 (jadikan `cohort.name` toggleable tersembunyi bila perlu).

### Teks `lang/id/pondok.php` (kunci baru)
`navigation.asrama`; `dormitory.*` (label, plural, code, name, gender, genders [L=Putra, P=Putri], location, rooms_count, occupancy, section_identity, unique); `room.*` (label, plural, name, floor, capacity, occupancy, unique, remaining); `room_assignment.*` (label "Penghuni asrama", plural, start_on "Tanggal masuk", end_on "Tanggal keluar", note, assign "Tempatkan santri", assigned, move "Pindah kamar", moved, vacate "Keluarkan dari asrama", vacate_description, vacated, non_resident "Nonmukim", statuses [aktif, riwayat], empty); `dormitory_supervisor.*` (label "Musyrif", plural, role, roles [kepala_asrama="Kepala asrama", musyrif="Musyrif"], whole_dormitory, assign "Tambah musyrif", assigned, end "Akhiri tugas", end_description, ended); `student.room`; `asrama.errors.*` (student_inactive, room_inactive, dormitory_inactive, gender_mismatch, room_full, no_active_academic_year, start_before_current, not_resident, end_before_start, invalid_role, staff_inactive, room_dormitory_mismatch, head_requires_dormitory, start_before_current_head, supervision_already_ended, capacity_min, capacity_below_occupancy, room_has_residents). Pesan error menyebut cara memperbaiki (§9), contoh `room_full`: "Kamar :room sudah penuh (:capacity santri). Pilih kamar lain atau tambah kapasitas."

## Permission & cakupan

### Permission baru
Tambahkan `'Dormitory', 'Room', 'RoomAssignment', 'DormitorySupervisor'` ke `RolePermissionSeeder::RESOURCE_MODELS`, lalu generate policy:
`php artisan shield:generate --option=policies --resource=DormitoryResource,RoomAssignmentResource --panel=admin -n` (policy `Room` dan `DormitorySupervisor` untuk relation manager dibuat dengan pola yang sama; bila Shield tidak membuatnya otomatis, salin pola `ClassroomPolicy`). Nama permission format Shield: `view-any:dormitory`, `create:room`, `update:room-assignment`, `view-any:dormitory-supervisor`, dst.

### Pembagian ke peran
| Peran | Permission asrama |
|---|---|
| super-admin | semua (otomatis lewat `allPermissions()`) |
| kesantrian | `Dormitory` dan `Room`: keenam aksi; `RoomAssignment` dan `DormitorySupervisor`: `viewAny`, `view`, `create`, `update` (tanpa delete, riwayat tidak dihapus) |
| musyrif | `RoomAssignment`: `viewAny`, `view` saja |
| peran lain | tidak ada |

`tests/Feature/Database/Seeders/RolePermissionSeederTest.php` dataset `kesantrian` dan `musyrif` wajib diperbarui ke daftar persis berikut:
- kesantrian: `view-dashboard`, `view-student-metrics`, `view-any:student`, `view:student`, `view-any:guardian`, `view:guardian`, `view-any:dormitory`, `view:dormitory`, `create:dormitory`, `update:dormitory`, `delete:dormitory`, `delete-any:dormitory`, `view-any:room`, `view:room`, `create:room`, `update:room`, `delete:room`, `delete-any:room`, `view-any:room-assignment`, `view:room-assignment`, `create:room-assignment`, `update:room-assignment`, `view-any:dormitory-supervisor`, `view:dormitory-supervisor`, `create:dormitory-supervisor`, `update:dormitory-supervisor`.
- musyrif: `view-dashboard`, `view-any:room-assignment`, `view:room-assignment`.
Dataset peran lain tidak berubah. Komentar "Lihat-saja; dibutuhkan modul asrama dan perizinan." di seeder diperbarui.

Otorisasi aksi bisnis: `Tempatkan santri` butuh `create:room-assignment`; `Pindah kamar` dan `Keluarkan dari asrama` butuh `update:room-assignment`; `Tambah musyrif` butuh `create:dormitory-supervisor`; `Akhiri tugas` butuh `update:dormitory-supervisor`. Semua lewat `->authorize(...)`.

### Cakupan data musyrif (Fase 1)
`RoomAssignment::scopeVisibleTo(Builder $query, User $user)`:
- Bila `$user->can('view-any:dormitory')` (kesantrian, super-admin) → tanpa batasan tambahan.
- Selain itu → hanya baris dengan `room_id` di kamar yang diawasi user: ambil `Staff` milik user (`staff.user_id`); dari penugasan `DormitorySupervisor::active()` milik staff itu, kamar yang diawasi = semua kamar asrama untuk penugasan dengan `room_id` null, ditambah `room_id` spesifik untuk penugasan per kamar. Kepala asrama dan musyrif diperlakukan sama.
- User tanpa staff tertaut atau tanpa penugasan aktif → hasil kosong (bukan error).
Ditunda ke fitur lain: persetujuan izin oleh musyrif (F024), dashboard musyrif (F018).

### Cakupan jenjang
`RoomAssignment` memakai `HasUnitScope` (jenjang dibaca lewat santri). `Dormitory`, `Room`, `DormitorySupervisor` tidak dibatasi jenjang karena asrama lintas jenjang. Hitungan penghuni dan cek kapasitas selalu memakai `withoutUnitScope()`.

### Saklar modul
`SettingSeeder::ENABLED_BY_DEFAULT` menjadi `['akademik', 'asrama']`. Saat `modules.asrama.enabled` false: semua halaman `DormitoryResource` dan `RoomAssignmentResource` 403, grup navigasi "Asrama" hilang, kolom "Kamar" di tabel santri tidak tampil. Action class tetap bisa dipanggil (dipakai PSB/keuangan dari kode).

## Kriteria penerimaan (bisa dites)

Penempatan (`AssignRoom`)
- AC-1: Santri aktif `L`, kamar aktif di asrama `L` berkapasitas 2 dan kosong, ada tahun ajaran aktif → `AssignRoom` → satu baris `room_assignments` dengan `room_id`, `student_id`, `academic_year_id` = tahun aktif, `start_on` = hari ini, `end_on` null.
- AC-2: Santri `P` ke kamar di asrama `L` → `ValidationException` dengan pesan `pondok.asrama.errors.gender_mismatch`; tidak ada baris baru.
- AC-3: Kamar kapasitas 1 sudah berisi satu penghuni aktif → santri lain ditempatkan → gagal `room_full`; jumlah baris tetap.
- AC-4: Kamar kapasitas 1 dengan satu penempatan yang sudah berakhir (`end_on` terisi) → penempatan baru berhasil (riwayat tidak dihitung sebagai penghuni).
- AC-5: Kapasitas dihitung lintas jenjang: user yang login hanya punya cakupan jenjang SLTP, kamar kapasitas 1 berisi santri SD aktif → user itu menempatkan santri SLTP → gagal `room_full`.
- AC-6: Santri berstatus `lulus` → gagal `student_inactive`. Kamar `is_active = false` → gagal `room_inactive`. Asrama `is_active = false` → gagal `dormitory_inactive`. Tidak ada tahun ajaran aktif → gagal `no_active_academic_year`.
- AC-7: Santri sudah mukim di kamar X → `AssignRoom` ke kamar X lagi → mengembalikan baris yang sama (id sama), jumlah baris tetap 1, `end_on` tetap null; berlaku juga walau kamar X penuh.

Pindah kamar & riwayat
- AC-8: Santri mukim di kamar A sejak 2026-07-15 → `AssignRoom` ke kamar B dengan `startOn` 2026-10-01 → baris A tetap ada dengan `end_on` = 2026-10-01 dan `room_id` tidak berubah; baris baru B dengan `start_on` 2026-10-01, `end_on` null, `note` sesuai argumen; total 2 baris untuk santri itu.
- AC-9: Pindah ke kamar B yang penuh → gagal `room_full` dan baris A tetap aktif (`end_on` null), tidak ada baris baru.
- AC-10: Pindah dengan `startOn` sebelum `start_on` penempatan aktif → gagal `start_before_current`; baris lama tidak berubah.
- AC-11: Setelah dua kali pindah (A → B → C), santri punya 3 baris dan tepat satu yang `end_on` null (kamar C).

Keluar asrama (`VacateRoom`)
- AC-12: Santri mukim → `VacateRoom` dengan tanggal dan alasan → baris aktif mendapat `end_on` dan `note`; tidak ada baris yang dihapus; `isResident()` menjadi false.
- AC-13: Santri tanpa penempatan aktif → `VacateRoom` → gagal `not_resident`. Tanggal keluar sebelum `start_on` → gagal `end_before_start`.

Status mukim
- AC-14: `isResident()` true hanya bila ada baris `end_on` null; false untuk santri tanpa baris dan santri yang semua barisnya berakhir.
- AC-15: Dengan 3 santri (aktif di kamar, pernah mukim lalu keluar, tidak pernah mukim) → `Student::resident()` mengembalikan tepat santri pertama; `Student::nonResident()` mengembalikan dua lainnya.
- AC-16: `$student->activeRoom` mengembalikan `Room` aktif (dengan `dormitory_id` yang benar) untuk santri mukim dan null untuk nonmukim/yang sudah keluar.
- AC-17: Penempatan aktif yang dibuat di tahun ajaran lama tetap aktif setelah tahun ajaran lain diaktifkan (`ActivateAcademicYear`); `isResident()` tetap true.

Musyrif (`AssignSupervisor`, `EndSupervision`)
- AC-18: Staff aktif ditugaskan sebagai musyrif seluruh asrama → baris dengan `room_id` null, `role = musyrif`, `academic_year_id` sesuai argumen, `end_on` null. Ditugaskan per kamar → `room_id` terisi.
- AC-19: Kamar dari asrama lain → gagal `room_dormitory_mismatch`. Staff nonaktif → gagal `staff_inactive`. Kepala asrama dengan kamar → gagal `head_requires_dormitory`. Peran tidak dikenal → gagal `invalid_role`.
- AC-20: Menugaskan penugasan aktif yang identik dua kali → id sama, tidak ada baris baru.
- AC-21: Kepala asrama X aktif di asrama D tahun Y → kepala asrama Z ditugaskan untuk D tahun Y dengan `startOn` T → baris X tetap ada dengan `end_on` = T; baris Z aktif. Dua musyrif berbeda di asrama yang sama keduanya tetap aktif. Kepala asrama X dengan `start_on` 2026-08-01 → kepala Z ditugaskan dengan `startOn` 2026-07-20 → gagal `start_before_current_head`; baris X tetap `end_on` null dan tidak ada baris Z.
- AC-22: `EndSupervision` mengisi `end_on`; memanggilnya lagi → gagal `supervision_already_ended`; tanggal sebelum `start_on` → gagal `end_before_start`.
- AC-23: `DormitorySupervisor::active()` tidak mengembalikan penugasan dari tahun ajaran yang tidak aktif maupun yang sudah berakhir.

Constraint database
- AC-24: Nama kamar ganda dalam satu asrama → `UniqueConstraintViolationException`; nama sama di asrama berbeda diizinkan. `dormitories.code` ganda → `UniqueConstraintViolationException`.
- AC-25: Menghapus asrama yang punya kamar, asrama yang hanya punya baris `dormitory_supervisors`, kamar yang punya baris `room_assignments`, atau kamar yang punya baris `dormitory_supervisors`, masing-masing melempar `QueryException` (restrict) dan data tetap ada.
- AC-38: Satu santri dengan satu baris aktif → insert langsung (tanpa Action) baris aktif kedua untuk santri yang sama → `UniqueConstraintViolationException`. Dua baris berakhir (`end_on` terisi) untuk santri yang sama ditambah satu baris aktif → diizinkan. Mengisi `end_on` pada baris aktif mengubah `active_marker` menjadi NULL, sehingga baris aktif baru boleh dibuat. `RoomAssignmentFactory` (termasuk state `ended()`) tetap bisa membuat data tanpa menulis `active_marker`.

Akses, cakupan, dan saklar modul (HTTP/Livewire)
- AC-26: `RolePermissionSeeder` memberi kesantrian dan musyrif persis daftar permission di bagian Permission & cakupan; dataset peran lain tidak berubah; test "creates every permission that a generated policy checks" tetap lulus dengan policy baru.
- AC-27: Kesantrian: `GET /admin/dormitories`, `/admin/dormitories/create`, `/admin/dormitories/{id}/edit`, `/admin/room-assignments` → 200.
- AC-28: Musyrif: `/admin/room-assignments` → 200; `/admin/dormitories` → 403. Di halaman penghuni, header action `Tempatkan santri` dan aksi baris `Pindah kamar`/`Keluarkan dari asrama` tidak terlihat (`assertActionHidden` / `assertTableActionHidden`).
- AC-29: Peran bendahara, admin-konten, admin-psb, admin-sekolah, ustadz-tahfidz, petugas-pos → `/admin/dormitories` dan `/admin/room-assignments` → 403.
- AC-30: Musyrif yang staff-nya ditugaskan ke asrama D (seluruh asrama, tahun aktif) melihat penghuni semua kamar D dan tidak melihat penghuni asrama lain (`assertCanSeeTableRecords` / `assertCanNotSeeTableRecords`). Musyrif yang ditugaskan per kamar R hanya melihat penghuni R. Penugasan tahun ajaran tidak aktif atau yang sudah berakhir tidak memberi akses. User musyrif tanpa staff tertaut melihat tabel kosong tanpa error.
- AC-31: User kesantrian dengan cakupan jenjang SLTP hanya melihat penempatan santri SLTP di daftar penghuni, dan opsi santri pada `Tempatkan santri` hanya santri SLTP.
- AC-32: `modules.asrama.enabled = false` → super-admin mendapat 403 di `/admin/dormitories` dan `/admin/room-assignments`, dan `/admin` tidak menampilkan teks `pondok.navigation.asrama`; saat aktif, teks itu tampil. Instalasi baru (`SettingSeeder` pada DB kosong) menghasilkan `modules.asrama.enabled = true`.

UI aksi (Livewire)
- AC-33: Kesantrian memanggil `Tempatkan santri` dari `ManageRoomAssignments` dengan data valid → baris aktif terbuat dan notifikasi sukses; dengan kamar penuh → tidak ada baris baru dan notifikasi danger berisi pesan `room_full` (tidak ada exception yang bocor).
- AC-34: Kesantrian memanggil `Pindah kamar` pada baris aktif → menghasilkan dua baris (lama berakhir, baru aktif). `Keluarkan dari asrama` → baris berakhir, santri tidak lagi muncul dengan filter bawaan `aktif` tetapi muncul dengan filter `riwayat`.
- AC-35: Kesantrian membuat asrama lewat form, lalu kamar lewat `RoomsRelationManager`; mengubah kapasitas kamar yang berisi 3 penghuni aktif menjadi 2 dari relation manager → error `capacity_below_occupancy` tampil dan kapasitas tetap 3. Aksi Delete asrama tersembunyi bila asrama hanya punya riwayat musyrif; aksi Delete kamar tersembunyi bila kamar punya riwayat penempatan atau riwayat musyrif (`assertTableActionHidden`).
- AC-39: `SaveRoom` (dipanggil langsung): membuat kamar baru milik asrama → tersimpan dengan `dormitory_id` asrama itu; `capacity` 0 → gagal `capacity_min`; kamar berpenghuni aktif 3 (santri dari dua jenjang berbeda, dipanggil oleh user dengan cakupan satu jenjang) diubah kapasitasnya menjadi 2 → gagal `capacity_below_occupancy`; menjadi 3 → berhasil; menonaktifkan kamar berpenghuni aktif → gagal `room_has_residents` dan `is_active` tetap true; menonaktifkan kamar yang penghuninya semua sudah keluar → berhasil.
- AC-36: Kesantrian `Tambah musyrif` lalu `Akhiri tugas` di `SupervisorsRelationManager` → baris terbuat, lalu `end_on` terisi; baris tidak hilang.
- AC-37: Tabel santri (`ListStudents`) menampilkan "Asrama — Kamar" untuk santri mukim dan "Nonmukim" untuk santri tanpa penempatan aktif saat modul asrama aktif; kolom tidak ada (`assertTableColumnDoesNotExist` atau tidak visible) saat modul asrama mati, dan halaman tetap 200. Saat modul asrama mati, render tabel santri tidak menjalankan query ke `room_assignments` (diverifikasi dengan `DB::listen`/query log berisi tidak ada SQL yang memuat `room_assignments`).

## Rencana test

Catatan: fungsi helper Pest di file test bersifat global. Jangan mendefinisikan ulang `userWithRole`, `classroomFor`, `studentFormData`, `billingContactIds`, `enrolledStudent`; pakai nama baru berawalan `asrama` (mis. `asramaUser()`, `asramaRoom()`) atau closure lokal.

| AC | File | Nama test yang diusulkan |
|---|---|---|
| AC-1, AC-4, AC-7 | `tests/Feature/Actions/Asrama/AssignRoomTest.php` | `it places an active student in a room of the matching dormitory`; `it ignores ended assignments when checking capacity`; `it returns the active assignment when assigned to the same room again` |
| AC-2, AC-3, AC-6 | idem | `it rejects the assignment` (dataset: other gender, full room, inactive student, inactive room, inactive dormitory, no active academic year) |
| AC-5 | idem | `it counts occupants from every unit when checking capacity` |
| AC-8 – AC-11 | idem | `it closes the old assignment and adds a new row when moving rooms`; `it keeps the current room when the target room is full`; `it rejects a move dated before the current assignment`; `it keeps exactly one active assignment after several moves` |
| AC-12, AC-13 | `tests/Feature/Actions/Asrama/VacateRoomTest.php` | `it ends the active assignment and makes the student nonresident`; `it rejects vacating` (dataset: not resident, end before start) |
| AC-14 – AC-17 | `tests/Feature/Models/StudentResidencyTest.php` | `it derives resident status from active room assignments`; `it filters resident and nonresident students`; `it exposes the active room`; `it keeps residency across academic year changes` |
| AC-18 – AC-21 | `tests/Feature/Actions/Asrama/AssignSupervisorTest.php` | `it assigns a supervisor to a whole dormitory or a room`; `it rejects the supervisor assignment` (dataset); `it returns the existing active assignment`; `it ends the previous head when a new head is assigned`; `it rejects a new head dated before the current head`; `it keeps several musyrif active in one dormitory` |
| AC-22, AC-23 | `tests/Feature/Actions/Asrama/EndSupervisionTest.php` | `it ends the supervision`; `it rejects ending` (dataset); `it scopes active supervisors to the active academic year` |
| AC-24, AC-25 | `tests/Feature/Database/AsramaConstraintsTest.php` | `it rejects a duplicate room name in one dormitory`; `it allows the same room name in another dormitory`; `it rejects a duplicate dormitory code`; `it restricts deleting a dormitory with rooms`; `it restricts deleting a dormitory or room with supervisor history`; `it restricts deleting a room with assignment history` |
| AC-38 | `tests/Feature/Database/AsramaConstraintsTest.php` | `it rejects a second active assignment for one student`; `it allows many ended assignments next to one active assignment`; `it frees the active slot when an assignment ends` |
| AC-39 | `tests/Feature/Actions/Asrama/SaveRoomTest.php` | `it creates a room in the dormitory`; `it rejects saving the room` (dataset: capacity zero, capacity below occupancy across units, deactivating an occupied room); `it allows capacity equal to occupancy`; `it deactivates a room without active residents` |
| AC-26 | `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (ubah dataset) | dataset `kesantrian`, `musyrif` di `grants each role exactly its permissions` |
| AC-27 – AC-29, AC-32 | `tests/Feature/Filament/AsramaAccessTest.php` | `it lets kesantrian manage dormitories and residents`; `it lets musyrif only view residents`; `it forbids asrama resources to other roles` (dataset); `it returns 403 and hides the menu when the asrama module is disabled`; `it enables the asrama module on a fresh install` |
| AC-30, AC-31 | `tests/Feature/Filament/Resources/RoomAssignments/RoomAssignmentVisibilityTest.php` | `it shows musyrif only residents of supervised dormitories`; `it shows musyrif only residents of a supervised room`; `it ignores ended or past-year supervisions`; `it shows nothing to a musyrif without staff`; `it limits residents and student options to the user units` |
| AC-28 (aksi tersembunyi), AC-33, AC-34 | `tests/Feature/Filament/Resources/RoomAssignments/RoomAssignmentResourceTest.php` | `it hides placement actions from musyrif`; `it places a student from the panel`; `it shows a danger notification when the room is full`; `it moves and vacates from the table` |
| AC-35, AC-36 | `tests/Feature/Filament/Resources/Dormitories/DormitoryResourceTest.php` | `it creates a dormitory and its rooms`; `it rejects a capacity below current occupancy`; `it hides delete for dormitories and rooms with history`; `it assigns and ends a supervisor` |
| AC-37 | `tests/Feature/Filament/Resources/Students/StudentResourceTest.php` (tambah) | `it shows the active room or nonresident in the student table`; `it hides the room column when the asrama module is disabled`; `it does not query room assignments when the asrama module is disabled` |

## Urutan implementasi

1. Migration `dormitories`, `rooms`, `room_assignments` (termasuk generated stored `active_marker` + unique), `dormitory_supervisors` + model + factory (+ state `AcademicYearFactory::active()`); relasi `Student`/`Staff`; `HasUnitScope` dan `scopeVisibleTo` pada `RoomAssignment` langsung dipasang di sini agar AC-5 benar-benar menguji cakupan. Test: AC-14 – AC-17, AC-24, AC-25, AC-38.
2. Action `AssignRoom` + `VacateRoom` + kunci `pondok.asrama.errors.*`. Test: AC-1 – AC-13.
3. Action `AssignSupervisor` + `EndSupervision` + scope `DormitorySupervisor::active()`; Action `SaveRoom`. Test: AC-18 – AC-23, AC-39.
4. Permission: `RESOURCE_MODELS`, pembagian peran, update `RolePermissionSeederTest`, `SettingSeeder::ENABLED_BY_DEFAULT`. Test: AC-26, bagian fresh install AC-32.
5. `DormitoryResource` + `RoomsRelationManager` (memakai `SaveRoom`) + `SupervisorsRelationManager` + policy Shield + lang. Test: bagian `/admin/dormitories` dari AC-27, AC-29, AC-32; AC-35, AC-36.
6. `RoomAssignmentResource` + aksi tempatkan/pindah/keluar + lang. Test: bagian `/admin/room-assignments` dari AC-27, AC-29, AC-32; AC-28, AC-30, AC-31, AC-33, AC-34.
7. Kolom "Kamar" di `StudentsTable` (eager-load bersyarat). Test: AC-37.
8. Pint, checklist design-guide §0, jalankan seluruh test terkait.

## Risiko & pertanyaan terbuka

Pertanyaan (default yang disarankan agar kerja bisa lanjut):
1. **Santri lulus/keluar/pindah apakah otomatis dikeluarkan dari kamar?** Default: tidak di F003. Kesantrian memakai `Keluarkan dari asrama`. Penempatan santri nonaktif tetap terhitung penghuni sampai dikeluarkan (kapasitas jujur terhadap data). Bila user ingin otomatis, usulkan entri baru setelah F003.
2. **Validasi gender staff terhadap asrama (musyrifah untuk asrama putri)?** Default: tidak divalidasi; banyak pondok punya kepala asrama putri laki-laki atau sebaliknya.
3. **Siapa yang "melihat semua penghuni"?** Default: pemegang `view-any:dormitory` (kesantrian, super-admin). Selain itu dibatasi ke asrama/kamar yang diawasi. Alternatif: permission khusus terpisah; ditunda kecuali user meminta.
4. **Satu kepala asrama aktif per asrama per tahun?** Default: ya, penugasan kepala baru menutup yang lama. Musyrif boleh banyak.
5. **Tanggal akhir baris lama saat pindah kamar** = tanggal mulai baris baru (bukan H-1). Default ini dipilih agar sederhana; status aktif hanya bergantung pada `end_on IS NULL`, jadi tidak ada tumpang tindih logika.
6. **Admin-sekolah perlu akses asrama?** Default: tidak (PRD 2 menaruh asrama di Kesantrian). Admin-sekolah tetap melihat kolom "Kamar" di tabel santri.

Risiko:
- Generated column stored dengan `CASE` harus didukung ketiga DB: SQLite ≥ 3.31 (stored generated column), MySQL ≥ 5.7 / MariaDB ≥ 10.2, Postgres ≥ 12. Implementer memastikan migration lulus di SQLite (test) dan menulis ekspresi SQL standar tanpa fungsi khusus satu DB. Pola sama sudah dipakai `fee_rates` (F004).
- Penguncian `AssignRoom` mengandalkan kunci baris induk (`students`, `rooms`); jalur lain yang menulis `room_assignments` (mis. PSB F012 kelak) wajib lewat `AssignRoom` agar urutan kunci sama. Unique `active_marker` tetap menjadi pengaman terakhir bila ada jalur yang lolos.
- `HasUnitScope` pada `RoomAssignment` bisa membuat hitungan kapasitas salah bila implementer lupa `withoutUnitScope()` (dijaga AC-5).
- Relasi `activeRoom()` memakai `HasOneThrough` dengan kunci terbalik; bila bermasalah lintas DB, implementer boleh menggantinya dengan accessor yang membaca `activeRoomAssignment->room`, asalkan AC-16 lulus dan F006 tetap bisa eager-load.
- Usulan entri baru (belum ditambahkan ke `feature_list.json`, menunggu keputusan user): "Salin penugasan musyrif dari tahun ajaran sebelumnya" (`asrama-salin-musyrif`), dan opsional "Keluarkan otomatis dari asrama saat status santri berubah" (`asrama-auto-keluar`).

## Riwayat revisi

- DRAFT 1 (product-manager): rencana awal.
- DRAFT 2 (product-manager, menanggapi Review #1 dan keputusan orkestrator):
  - #1 (major): `AssignRoom` mengunci baris induk `Student` (`withoutUnitScope`, `withTrashed`) lalu `Room` dengan `lockForUpdate()`, kemudian menghitung penghuni dengan `count()` biasa. Tidak ada `FOR UPDATE` pada agregat. Langkah Action dinomori ulang (5–9).
  - #2 (major): tambah kolom generated stored `active_marker` + unique (`student_id`, `active_marker`) di `room_assignments`; AC-38 baru dan test di `AsramaConstraintsTest`. **Perubahan ERD:** satu baris ditambahkan pada tabel constraint `docs/erd.md` untuk `room_assignments` (disetujui orkestrator). Risiko lama "hanya dijaga di Action" diganti catatan versi DB.
  - #3: syarat tampil Delete asrama/kamar juga memeriksa `dormitory_supervisors`; AC-25 dan AC-35 diperluas.
  - #4, #5: urutan implementasi diperbaiki; `HasUnitScope` dipasang di langkah 1, bagian `/admin/room-assignments` dari AC-27/29/32 dipindah ke langkah resource penghuni. Langkah kini 1–8.
  - #6: `AssignSupervisor` menolak kepala baru dengan `startOn` sebelum `start_on` kepala lama (`start_before_current_head`); kasus ditambahkan ke AC-21.
  - #7: aturan kapasitas pindah ke Action baru `SaveRoom` (dipakai relation manager); kapasitas di bawah penghuni aktif ditolak, dan menonaktifkan kamar berpenghuni **ditolak** (`room_has_residents`). AC-39 baru; AC-35 menguji lewat UI.
  - #8: eager-load `activeRoom.dormitory` di `StudentsTable` hanya bila modul asrama aktif; AC-37 diperluas.
  - Jumlah AC: 39.

## Review #1 — 2026-10-15

Verdict: CHANGES_REQUESTED

Catatan: jawaban default PM untuk pertanyaan terbuka 1–6 diterima; `asrama-salin-musyrif` dan `asrama-auto-keluar` tidak ditambahkan sekarang. Cakupan AC terhadap `acceptance` F003 dan PRD 3.3 lengkap, dan model data sesuai ERD (kecuali butir 2).

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | Action `AssignRoom` langkah 5–6 (`lockForUpdate`) | Langkah 6 menghitung penghuni dengan `lockForUpdate` pada query agregat. Postgres menolak `FOR UPDATE` pada agregat, sehingga melanggar CLAUDE.md "kompatibel Postgres/portabel". Pada SQLite `lockForUpdate` tidak berefek. Langkah 5 mengunci nol baris untuk santri yang belum mukim, sehingga tidak ada yang diserialkan. Dua permintaan bersamaan (klik ganda) bisa sama-sama lolos cek kapasitas atau sama-sama membuat baris aktif. | Dalam transaksi, kunci baris induk: `Room::lockForUpdate()->findOrFail()` (serialisasi kapasitas per kamar) dan `Student::withoutUnitScope()->lockForUpdate()` (serialisasi per santri). Lalu hitung penghuni dengan `count()` biasa. Perbarui langkah 5–6 dan catatan di bagian Model data. |
| 2 | major | Model data `room_assignments`, Risiko #1 (satu penempatan aktif hanya dijaga di Action) | Satu baris aktif per santri menentukan status mukim, dan status itu dipakai untuk penagihan biaya `applies_to = mukim` (F006) dan approver izin (F024). Dua baris aktif membuat santri ditagih ganda atau `activeRoom` ambigu, dan kerusakan ini diam-diam. CLAUDE.md sudah menetapkan pola generated column untuk unique dengan nullable (`fee_rates`), jadi alternatif portabel sudah ada dan murah. Alasan "agar sesuai ERD" tidak cukup, karena ERD bagian constraint memakai pola yang sama. | Tambahkan kolom generated stored `active_marker` = `CASE WHEN end_on IS NULL THEN 1 END` (NULL bila riwayat) dengan unique (`student_id`, `active_marker`). Gunakan `storedAs` agar jalan di SQLite, MySQL/MariaDB, dan Postgres (NULL di unique dianggap berbeda di ketiganya). Tambahkan AC baru: dua baris aktif untuk satu santri melempar `UniqueConstraintViolationException` (dua baris berakhir tetap boleh), beserta test di `AsramaConstraintsTest`. Pastikan factory `ended()` dan Action tetap lulus. Perbarui tabel constraint di `docs/erd.md` (butuh persetujuan karena dokumen acuan). |
| 3 | minor | UI `DormitoryResource` (Delete hanya bila `rooms_count = 0`; delete kamar hanya bila tanpa penempatan) | `dormitory_supervisors.dormitory_id` dan `room_id` juga `restrictOnDelete`. Menghapus asrama atau kamar yang hanya punya riwayat musyrif akan melempar `QueryException` di UI. | Syarat tampil Delete harus juga mensyaratkan tidak ada baris `dormitory_supervisors`. Tambahkan ke AC-25 atau AC-35. |
| 4 | minor | Urutan implementasi, langkah 2 vs 5 | AC-5 (kapasitas lintas jenjang) diuji di langkah 2, padahal `HasUnitScope` pada `RoomAssignment` baru dipasang di langkah 5. Sebelum itu test lulus tanpa menguji apa pun. | Pindahkan pemasangan `HasUnitScope` dan `scopeVisibleTo` ke langkah 1 atau awal langkah 2. |
| 5 | minor | Urutan implementasi, langkah 6 | Langkah 6 mencantumkan AC-27 dan AC-29, yang memeriksa `/admin/room-assignments`. Resource itu baru dibuat di langkah 7. | Pindahkan bagian `room-assignments` dari AC-27/29 ke langkah 7, atau tukar urutan. |
| 6 | minor | Action `AssignSupervisor` | Penutupan kepala lama memakai `end_on = startOn` tanpa memeriksa `startOn >= start_on` kepala lama (berbeda dengan `AssignRoom` yang punya `start_before_current`). Bisa menghasilkan `end_on < start_on`. | Tambahkan validasi yang sama atau lewati dengan alasan tertulis. Tambahkan satu kasus ke AC-21. |
| 7 | minor | UI `RoomsRelationManager` | Validasi "kapasitas tidak boleh kurang dari penghuni aktif" hanya di form. Kamar dengan penghuni aktif juga bisa dinonaktifkan tanpa peringatan, padahal penghuninya tetap terhitung. | Pindahkan aturan kapasitas ke rule yang memakai `Room::occupiedCount()` (tanpa cakupan jenjang). Catat perilaku penonaktifan kamar berpenghuni dalam rencana: diperbolehkan atau ditolak. |
| 8 | minor | Tabel santri (`StudentsTable`) | Eager-load `activeRoom.dormitory` ditulis tanpa syarat, jadi tetap jalan saat modul asrama mati. | Eager-load hanya bila `Setting::moduleEnabled('asrama')`. |

Jawaban untuk pertanyaan orkestrator: constraint DB portabel layak dan sebaiknya dipakai (butir 2), ditambah perbaikan penguncian (butir 1). Dengan keduanya, jalur klik ganda tidak merusak data di ketiga DB.

## Review #2 — 2026-10-15

Verdict: APPROVED

Semua temuan Review #1 sudah tertangani: #1 (kunci baris induk `Student` lalu `Room`, `count()` biasa, urutan kunci tetap), #2 (`active_marker` stored + unique, AC-38, baris ERD ditambahkan), #3 (Delete memeriksa `dormitory_supervisors`, AC-25 dan AC-35), #4 dan #5 (urutan langkah), #6 (`start_before_current_head`, AC-21), #7 (Action `SaveRoom`, AC-39), #8 (eager-load bersyarat, AC-37). Tidak ada blocker atau major baru.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | `AssignRoom` langkah 1–4 | Validasi status santri, kamar aktif, dan gender dibaca dari model yang dimuat sebelum transaksi, sehingga bisa usang jika berubah bersamaan. Langkah 5 sudah membaca ulang `capacity`/`is_active` kamar. | Saat implementasi, ulangi cek `student.status` pada baris santri yang terkunci. Tidak perlu AC baru. |
| 2 | minor | `docs/erd.md` | Baris constraint `active_marker` sudah ada, tetapi diagram `room_assignments` di bagian Asrama tidak memuat kolomnya. | Biarkan (kolom turunan) atau tambahkan catatan satu baris pada diagram. |
