# F059 asrama-import-penghuni — Import penghuni asrama dari CSV

Issue: #28
Status rencana: DONE

## Ringkasan & tujuan
Admin kesantrian menempatkan ratusan santri ke kamar sekaligus lewat CSV. Memakai dasar F058 (`ImportAction` + `Importer` Filament, tabel `imports`/`failed_import_rows`/`notifications`, notifikasi database, pola antrean `database`/`sync`). Satu baris = satu penempatan. Penempatan sebenarnya SELALU lewat `AssignRoom` (kapasitas, gender, jenjang aktif, tutup penempatan lama, kunci baris), tidak ada logika penempatan baru.

## Ruang lingkup
- Termasuk: tombol Import + Unduh template CSV di halaman Penghuni (`ManageRoomAssignments`); `RoomAssignmentImporter`; Action `ImportRoomAssignmentRow`; permission `import:room-assignment`; ekstraksi DRY pemakai kedua (lihat Keputusan 4).
- Tidak termasuk (eksplisit): `.xlsx`; membuat asrama/kamar otomatis; kolom catatan (`note` null); kolom tahun ajaran (selalu tahun ajaran aktif, perilaku `AssignRoom`); mengeluarkan santri dari asrama via file; musyrif (`dormitory_supervisors`).

## Keputusan
1. Kolom CSV (judul Indonesia, kunci snake_case): `nis`*, `dormitory`* (Asrama), `room`* (Kamar), `start_on` (Tanggal mulai, `YYYY-MM-DD`, opsional; kosong = hari ini, sama dengan default form manual).
2. Pencocokan: asrama by `name` case-insensitive + trim; kamar by `name` case-insensitive di dalam asrama itu. Tidak ketemu atau lebih dari satu cocok → baris gagal. Asrama/kamar nonaktif ditolak oleh `AssignRoom`.
3. Santri dicari satu query: `Student::query()->where('nis', $nis)->first()` (UnitScope otomatis, karena queue memanggil `auth()->setUser($import->user)`). Tidak ketemu = pesan sama untuk "tidak ada" dan "di luar cakupan jenjang" (tidak membocorkan keberadaan). TIDAK dibagi dengan `ImportStudentRow` (yang itu butuh `withoutUnitScope()->withTrashed()` untuk menjaga unique NIS; kebutuhannya berbeda). Santri soft-deleted tidak ketemu (scope default) → gagal.
4. DRY, pemakai kedua nyata: (a) trait `app/Filament/Concerns/HasCsvImportActions` dengan satu method `csvImportActions(string $importer, string $templateFileName, string $modalDescription, Closure $visible, int $maxRows = 2000): array` yang mengembalikan [aksi unduh template, `ImportAction`] (isi dipindah dari `ListStudents`: `downloadImportTemplate` + `modalDescription` + `downloadExample`); dipakai `ListStudents` dan `ManageRoomAssignments`. (b) kelas dasar `app/Filament/Imports/CsvImporter extends Importer` berisi `$shouldPreventFormulaInjection = true`, helper `column()` (label/guess/rules, prefix lang lewat properti statis `$langPrefix`), dan `modifyCompletedNotification` (sendToDatabase saat `sync`); `StudentImporter` dan importer baru mewarisinya. Refactor ini tidak boleh mengubah perilaku F058 (test F058 tetap hijau tanpa diubah).
5. Idempotensi: `AssignRoom` mengembalikan penempatan aktif bila kamar sama, tanpa baris baru dan tanpa memandang `start_on`. Jadi import ulang, termasuk dengan tanggal mulai berbeda, tidak menggandakan dan tidak mengubah `start_on` lama. Baris seperti ini dihitung berhasil (no-op).
6. Santri pindah kamar lewat file: `AssignRoom` menutup baris lama (`end_on = start_on`) dan membuat baris baru; `start_on` lebih awal dari penempatan lama → gagal (`start_before_current`).

## Model data
Tanpa migration. Tabel `room_assignments`, `rooms`, `dormitories`, `imports`, `failed_import_rows`, `notifications` sudah ada. Tidak ada kolom baru.

## Action class
`app/Actions/Asrama/ImportRoomAssignmentRow` — input array baris (`nis`, `dormitory`, `room`, `start_on`), output `RoomAssignment`. Aturan: (1) cari santri (Keputusan 3), (2) cari asrama lalu kamar (Keputusan 2), (3) `start_on` kosong → `null`, terisi → `Carbon::parse`, (4) panggil `AssignRoom->handle($student, $room, $startOn)`. Kegagalan `ValidationException` dari `AssignRoom` (pesan Indonesia dari `pondok.asrama.errors.*`) dan pesan baru "santri/asrama/kamar tidak ditemukan" (lang `pondok.asrama.import.errors.*`) masuk file baris gagal. Satu baris = satu transaksi (di dalam `AssignRoom`). Tanpa efek samping lain.

## UI (Filament)
- `ManageRoomAssignments::getHeaderActions()`: `...csvImportActions(...)` lalu `assignAction()`; visible bila `can('import', RoomAssignment::class)`.
- `RoomAssignmentImporter::getColumns()` dengan `example()` fiktif: NIS `2026001`, asrama `Asrama Abu Bakar`, kamar `Kamar 101`, tanggal `2026-07-15`.
- Notifikasi selesai: "{n} penghuni ditempatkan, {m} baris gagal." Teks di `lang/id/pondok.php` bagian `asrama.import`. Modal menjelaskan nama asrama/kamar harus persis seperti di sistem dan hasil bisa 1-2 menit (antrean `database`). Rujukan design-guide: bagian aksi dan notifikasi; checklist §0. Tanpa Blade baru.

## Permission & cakupan
- Permission baru `import:room-assignment`: tambah `'RoomAssignment' => ['import']` ke `EXTRA_RESOURCE_ACTIONS` dan `import(AuthUser)` di `RoomAssignmentPolicy` (pola `StudentPolicy::import`).
- Peran: `super-admin` (otomatis) dan `kesantrian` (tambah `'import'` ke daftar aksi RoomAssignment-nya). `admin-sekolah`, `musyrif`, `bendahara`, dan lainnya tidak.
- Cakupan jenjang lewat UnitScope pada pencarian santri. Modul asrama: halaman sudah mengikuti saklar `modules.asrama.enabled`; tidak ada route baru.

## Kriteria penerimaan (bisa dites)
- AC-1: Pengguna `kesantrian`/`super-admin` melihat aksi Import dan Unduh template di `ManageRoomAssignments`; `musyrif`, `admin-sekolah`, `bendahara` tidak melihat dan tidak bisa memanggilnya.
- AC-2: Template memuat judul Indonesia untuk semua kolom di Keputusan 1 dan satu baris contoh fiktif yang bila diimpor (dengan data asrama/kamar cocok) sukses; unduhan CSV ber-BOM UTF-8. Tombol di `ListStudents` tetap berfungsi seperti F058 setelah ekstraksi trait.
- AC-3: Baris valid (NIS ada, asrama dan kamar cocok beda huruf besar/kecil) membuat satu `room_assignments` aktif (`end_on` null, tahun ajaran aktif, `start_on` sesuai kolom); `start_on` kosong → hari ini.
- AC-4: Santri sudah aktif di kamar lain: baris lama ditutup (`end_on = start_on` baru), baris baru dibuat, riwayat tetap (2 baris); `start_on` sebelum penempatan lama → baris gagal dan data tidak berubah.
- AC-5: Kapasitas penuh dan jenis kelamin asrama tidak cocok → baris gagal dengan alasan Indonesia dari `AssignRoom` di `failed_import_rows`; baris valid lain di file sama tetap masuk; notifikasi "n berhasil, m gagal".
- AC-6: Asrama tidak ada, kamar tidak ada di asrama itu (kamar ada di asrama lain), NIS tidak ada, `start_on` bukan `YYYY-MM-DD`, kolom wajib kosong → gagal dengan alasan Indonesia; asrama/kamar tidak dibuat otomatis (jumlah `dormitories`/`rooms` tetap).
- AC-7: Idempotent: mengimpor file sama dua kali menjaga jumlah `room_assignments` tetap; import ulang dengan `start_on` berbeda untuk kamar yang sama tidak menambah baris dan tidak mengubah `start_on` lama.
- AC-8: Santri nonaktif (lulus/keluar), soft-deleted, atau kamar/asrama nonaktif → baris gagal tanpa penempatan.
- AC-9: User ber-`unit_user` terbatas: NIS santri jenjang lain gagal ("tidak ditemukan"), santri dalam cakupan sukses; user tanpa `unit_user` melihat semua jenjang.
- AC-10: File baris gagal tidak berisi formula aktif (`=`, `+`, `-`, `@` diawali `'`), sama seperti F058, lewat kelas dasar bersama.
- AC-11: Notifikasi database terkirim ke pengimpor saat selesai, baik antrean `sync` maupun `database` (setelah `queue:work --stop-when-empty`).
- AC-12: Permission `import:room-assignment` hanya dimiliki `super-admin` dan `kesantrian` (daftar persis di test seeder diperbarui; peran lain tidak berubah).

## Rencana test
- `tests/Feature/Filament/RoomAssignments/ImportRoomAssignmentsTest.php` (pola `ImportStudentsTest`, helper CSV sendiri): AC-1 (visibilitas per peran), AC-2 (template + round-trip contoh), AC-3, AC-4, AC-5, AC-6, AC-7, AC-8, AC-9, AC-10, AC-11.
- `tests/Feature/Actions/Asrama/ImportRoomAssignmentRowTest.php` (ringan): pencocokan nama case-insensitive dan kamar dalam asrama yang benar (AC-3, AC-6).
- Perbarui test seeder permission yang ada (AC-12). `ImportStudentsTest` tidak diubah dan harus tetap hijau (AC-2 bagian ekstraksi, AC-10).

## Urutan implementasi
Paket 1 (refactor DRY, test hijau: `ImportStudentsTest`, `StudentResource` terkait):
1. Kelas dasar `CsvImporter`, `StudentImporter` mewarisinya.
2. Trait `HasCsvImportActions`, `ListStudents` memakainya.
Paket 2 (data + Action, test: `ImportRoomAssignmentRowTest`, test seeder):
3. Permission `import:room-assignment` (seeder, policy, `kesantrian`).
4. `ImportRoomAssignmentRow` + lang error.
Paket 3 (Filament, test: `ImportRoomAssignmentsTest`):
5. `RoomAssignmentImporter` + aksi di `ManageRoomAssignments` + lang UI.
6. Pint dan cek tampilan 360px/1024px/desktop (modal Import).

## Risiko & pertanyaan terbuka
- Import ulang file lama setelah santri pindah kamar secara manual akan memindahkannya kembali (file dianggap sumber kebenaran). Wajar untuk impor data awal; dicatat di teks modal, tanpa kode tambahan.
- Nama asrama/kamar ambigu (dua cocok) digagalkan, bukan memilih salah satu.
- Pertanyaan terbuka: tidak ada. Rekomendasi `admin-sekolah` tidak diberi permission karena memang tidak mengelola penghuni.

## Riwayat revisi
(belum ada)

## Review #1 — 2026-10-19

Verdict: APPROVED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Keputusan 4 (trait/kelas dasar) | Ekstraksi proporsional: pemakai kedua nyata, ±40 baris unduh-template+modal sama persis dengan `ListStudents`, dan `StudentImporter::column()` memang private static yang akan disalin. Namun `$modalDescription` bertipe `string`, padahal `ListStudents` memakai closure dinamis (daftar kode jenjang dari DB). | Ketik parameter `Closure\|string`, atau pindahkan pembuatan teks ke pemanggil. Jaga kelas dasar sekecil mungkin (3 hal yang disebut); jangan tambah hook lain. Properti statis `$langPrefix` dapat diganti parameter `column()` bila lebih sederhana. |
| 2 | minor | Permission (b) | Pemilik `super-admin` + `kesantrian` sudah tepat: di `RolePermissionSeeder` hanya `kesantrian` (dan super-admin) yang punya `create:room-assignment`; `admin-sekolah` tidak punya permission RoomAssignment sama sekali. `RoomAssignment` memakai `$withoutDelete` (bukan `RESOURCE_ACTIONS` penuh), jadi `'import'` harus ditambahkan eksplisit ke daftar kesantrian dan ke `EXTRA_RESOURCE_ACTIONS` agar `allPermissions()` memuatnya. | Tambah di test seeder bahwa `musyrif` tetap tanpa `import`. Pertimbangkan `visible` mensyaratkan `import` saja (cukup); jangan tambah cek `create`. |
| 3 | minor | Keputusan 2 | `dormitories.name` tidak unique (hanya `code` unique); `rooms` unique (`dormitory_id`,`name`) tetapi case-sensitive di DB, sehingga cocok-ganda hanya mungkin lewat pencocokan case-insensitive. Penolakan saat ambigu sudah benar. | Tambah satu kasus test ambigu (dua asrama bernama sama) di test Action ringan; tidak perlu kolom `code`. |
| 4 | minor | Keputusan 5/AC-7 | Idempotensi dan `start_on` konsisten dengan `AssignRoom` (return `$current` bila kamar sama sebelum cek kapasitas dan `start_before_current`). Catatan: kamar penuh tidak mempengaruhi no-op. | AC-7 sebaiknya juga menegaskan no-op pada kamar yang penuh karena santri itu sendiri penghuninya. |
| 5 | minor | AC-9 | `Student::query()->where('nis')` memakai UnitScope; `AssignRoom` mengunci dengan `withoutUnitScope` setelah santri lolos scope, aman. Pastikan test memakai antrean dengan `auth()->setUser($import->user)` (sync dan database), bukan hanya sync. | Satu test AC-9 lewat jalur `queue:work --stop-when-empty` cukup (bisa digabung dengan AC-11). |

Cakupan AC terhadap issue #28: semua 5 kriteria tertutup (AC-1/2, AC-6, AC-3/4, AC-5, AC-7). Ukuran rencana wajar (12 AC, ±75 baris). Tidak ada temuan blocker atau major.
