# F058 akademik-import-santri — Import santri, angkatan, dan wali dengan template

Issue: #27
Status rencana: DRAFT

## Ringkasan & tujuan
Admin mengisi data awal ratusan santri lewat CSV (Excel, Save as CSV; keputusan user 19 Okt 2026). Memakai `ImportAction` + `Importer` bawaan Filament 5 (sudah diverifikasi di `vendor/filament/actions/src`): template contoh, file baris gagal, notifikasi hasil. Satu baris = satu santri + wali 1 dan wali 2.

## Ruang lingkup
- Termasuk: tombol Import (dengan unduh template di modal) di daftar Santri; `StudentImporter`; Action `ImportStudentRow`; migrasi `imports`, `failed_import_rows`, `notifications`; aktifkan notifikasi database di panel; permission `import:student`.
- Tidak termasuk: `.xlsx`; penghuni asrama, tagihan, staff (F059–F061); kolom NIK/KK; foto/alamat/NISN; kolom status (santri baru selalu `aktif`); abstraksi import generik untuk F059–F061 (mereka membuat Importer sendiri; yang dipakai ulang hanya tabel + notifikasi + pola).
- Penyimpangan dari issue: AC "NIK/KK terenkripsi" tidak berlaku, karena `students` dan `guardians` tidak punya kolom NIK/KK (hanya `documents.number`, cast encrypted, tidak diimpor). Body issue perlu diperbarui.

## Model data
- Tabel baru lewat migrasi bawaan Filament (publish `vendor/filament/actions/database/migrations`: `create_imports_table`, `create_failed_import_rows_table`) dan `php artisan make:notifications-table`. `job_batches` dan `failed_jobs` sudah ada (`0001_01_01_000002_create_jobs_table`). Tabel ini bukan data keuangan; FK bawaan dipertahankan.
- Tidak ada perubahan `students`, `guardians`, `cohorts`, `enrollments`. Tanpa soft delete baru.

## Importer (API Filament 5)
`App\Filament\Imports\StudentImporter extends Filament\Actions\Imports\Importer`; `$model = Student::class`; kolom `Filament\Actions\Imports\ImportColumn` dengan `->label()`, `->requiredMapping()`, `->rules([...])`, `->example()`, `->guess([...])`, `->castStateUsing()`.
- Kolom (judul CSV, snake_case): `nis`*, `name`*, `gender`* (`L`/`P`), `birth_place`*, `birth_date`* (`YYYY-MM-DD`), `unit`* (kode atau nama jenjang), `entry_year`* (4 digit), `classroom` (opsional), `guardian1_name`, `guardian1_relation` (`ayah|ibu|wali`), `guardian1_phone`, `guardian1_billing` (`ya|tidak`), dan `guardian2_*` setara (opsional seluruhnya).
- `resolveRecord()` memanggil Action (bukan `find`). `fillRecord`/`saveRecord` bawaan tidak dipakai untuk relasi; `saveRecord()` di-override memanggil `ImportRow`. Kegagalan memakai `RowImportFailedException` (pesan Indonesia dari `lang/id`) atau `ValidationException`; keduanya masuk file baris gagal dengan alasannya (terverifikasi di `ImportCsv::handle`).
- `protected static bool $shouldPreventFormulaInjection = true;` (bawaan Filament `false`; bila true, nilai berawalan `= + - @` diawali `'` di file baris gagal). Efek samping round-trip: telepon `+62…` menjadi `'+62…` saat diunggah ulang, jadi normalisasi telepon membuang `'` di depan.
- `getCompletedNotificationBody()` memakai `lang/id`: "{n} santri diimpor, {m} baris gagal" (Filament otomatis menambah tombol unduh baris gagal).
- Data contoh (`->example()`): fiktif, Islami netral (mis. NIS `2026001`, "Ahmad Fauzan", "Abdullah Rahman", "Khadijah Aisyah", telepon `081234567890`, jenjang `SLTP`).
- `ImportAction::make()->importer(StudentImporter::class)->chunkSize(100)->maxRows(2000)->visible(fn () => auth()->user()?->can('import', Student::class))`; label dan modal dari `lang/id`. Tombol di `ListStudents::getHeaderActions()` di sebelah `CreateAction`. `ImportAction` bawaan menyediakan tombol "Unduh contoh file CSV" di modal (header + baris `example`).

## Action class
`app/Actions/Akademik/ImportStudentRow` — input array baris yang sudah dicast, `User` pengimpor via `auth()` (queue memanggil `auth()->setUser($import->user)`, jadi UnitScope ikut berlaku); output `Student`. Satu transaksi per baris. Aturan:
1. Jenjang: cari `Unit` by `code` (case-insensitive), lalu `name`; tidak ketemu → gagal.
2. Cakupan: bila `scopedUnitIds()` tidak kosong dan jenjang di luarnya → gagal ("Anda tidak berwenang mengimpor ke jenjang ini").
3. Angkatan: `FindOrCreateCohort` (jenjang + tahun masuk). Pakai ulang `CreateStudent` untuk santri baru (kecocokan cohort–unit).
4. Upsert by NIS: `nis` UNIK GLOBAL (`students.nis unique`, termasuk terhapus). Lookup memakai `Student::withoutUnitScope()->withTrashed()->where('nis')`, agar upsert tidak menduplikasi NIS jenjang lain dan melanggar unique. Hasil: ditemukan di jenjang di luar cakupan → gagal; ditemukan terhapus (soft delete) → gagal ("NIS milik santri yang dihapus"); ditemukan aktif → update kolom template (name, gender, birth_*, cohort via tahun masuk); jenjang berbeda dari data lama → gagal (pindah jenjang lewat `ChangeStudentUnit`, di luar F058). Santri baru: `status=aktif`, `entered_on` = 1 Juli tahun masuk.
5. Rombel opsional: kosong → tidak ada enrollment. Terisi → cari `Classroom` by nama + jenjang di tahun ajaran aktif (`AcademicYear::active()`), lalu `EnrollStudent`. Rombel tidak ditemukan, tahun ajaran aktif tidak ada, atau gender/kapasitas ditolak → seluruh baris gagal (rollback), supaya data tidak masuk setengah.
6. Wali: normalisasi telepon (buang spasi, `-`, `'` depan; `+62`/`62` → `0`; hanya digit, 8–15). `Guardian::firstOrCreate(name, phone_normalized)` (nama dibandingkan case-insensitive, trim). Hubungkan lewat `AttachGuardian` (idempotent `syncWithoutDetaching`); `billing` = `ya` → `SetBillingContact`; wali pertama otomatis kontak tagihan (perilaku `AttachGuardian`). Wali 2 tanpa nama dan telepon = dilewati; salah satu terisi saja → gagal. Re-import tidak menduplikasi wali/pivot.

## Permission & cakupan
- Permission baru `import:student` (format `resourcePermission('import','Student')`): tambah `'import'` ke `EXTRA_RESOURCE_ACTIONS['Student']`; tambah `import(User)` di `StudentPolicy` (pola policy lain). Karena `admin-sekolah` memakai `resourcePermissions('Student')` default, ia otomatis dapat; `super-admin` semua; `kesantrian` (readOnly) dan peran lain tidak. Perbarui `RolePermissionSeederTest` (daftar persis permission dan peran).
- Panel: `AdminPanelProvider` tambah `->databaseNotifications()` dan `->databaseNotificationsPolling('30s')` (belum aktif; terverifikasi tidak ada di app/). Modul `akademik` inti, tanpa saklar modul.
- Cakupan jenjang: lihat Action butir 2 dan 4.

## Perilaku cPanel
- `QUEUE_CONNECTION=database` (default `.env.example`): ImportAction mengirim batch job ke `jobs`/`job_batches`; `routes/console.php` sudah menjadwalkan `queue:work --stop-when-empty --max-time=50` tiap menit, jadi hasil muncul ±1–2 menit setelah unggah (+ polling 30 dtk). Modal/notifikasi "diproses" memuat teks itu (lang/id). Tidak ada perubahan scheduler.
- `QUEUE_CONNECTION=sync`: batch berjalan langsung saat unggah; notifikasi hasil langsung muncul. Keduanya dites.

## UI
Tombol di header daftar Santri (design-guide §0 dan bagian aksi/notifikasi; teks Indonesia di `lang/id/pondok.php` bagian `akademik.import`). Pesan validasi lewat `->validationMessages`/`lang/id/validation.php` dan `->validationAttribute()` Indonesia. Tanpa Blade baru.

## Kriteria penerimaan
- AC-1: Pengguna dengan `import:student` melihat aksi Import di `ListStudents`; `kesantrian`, `bendahara`, dan peran tanpa permission tidak melihat dan tidak bisa memanggilnya (403/disembunyikan).
- AC-2: Contoh template (`getColumns()` + `example`) memuat semua kolom di atas dan satu baris fiktif yang bila diimpor sukses; tidak mengandung istilah terlarang (Maulid dst.).
- AC-3: Baris valid dengan jenjang ber-kode atau ber-nama (case-insensitive) membuat santri `aktif`, `unit_id`, `cohort_id` benar, `entered_on` = 1 Juli tahun masuk.
- AC-4: Angkatan baru dibuat otomatis bila belum ada (nama "Angkatan {tahun}"); dua baris jenjang+tahun sama memakai satu angkatan.
- AC-5: Wali dengan nama + telepon sama (termasuk format `+62812…` vs `0812…`) dipakai ulang oleh kakak-adik: satu baris `guardians`, dua baris `student_guardian`; relasi dan `is_billing_contact` sesuai kolom; wali 2 kosong dilewati; wali 2 setengah terisi gagal.
- AC-6: Idempotent: mengimpor file yang sama dua kali menjaga jumlah `students`, `guardians`, `student_guardian`, `cohorts`, `enrollments` tetap; NIS ada → data santri diperbarui. Baris dengan NIS milik santri jenjang lain/terhapus gagal dan tidak menggandakan.
- AC-7: Rombel ditemukan (tahun ajaran aktif) → satu `enrollments`; kosong → tanpa enrollment, baris sukses; rombel tak ada atau tidak cocok gender → baris gagal tanpa santri/wali tersimpan (rollback).
- AC-8: Baris tidak valid (NIS kosong, jenjang tidak dikenal, tanggal salah, gender/hubungan salah, tahun masuk bukan 4 digit) gagal dengan alasan Indonesia di `failed_import_rows`; baris valid lain di file yang sama tetap masuk; ringkasan notifikasi "n berhasil, m gagal".
- AC-9: User ber-`unit_user` terbatas: baris ke jenjang di luar cakupan gagal dengan alasan Indonesia, baris dalam cakupan sukses; upsert NIS jenjang lain tidak menimpa/menduplikasi.
- AC-10: File baris gagal tidak mengandung formula aktif: nilai berawalan `=`, `+`, `-`, `@` diawali `'`; telepon `'+62…` diimpor ulang ternormalisasi.
- AC-11: Notifikasi database terkirim ke pengimpor saat selesai (`notifications` terisi) dan panel menampilkan lonceng; dengan antrean `database` pekerjaan menunggu worker (`queue:work --stop-when-empty` memproses sampai selesai), dengan `sync` selesai langsung.
- AC-12: Permission `import:student` ada di `RolePermissionSeeder::allPermissions()` hanya untuk `super-admin` dan `admin-sekolah` (test daftar persis diperbarui); scheduler tetap memuat `queue:work --stop-when-empty`.

## Rencana test
- `tests/Feature/Akademik/ImportStudentRowTest.php` (Action langsung): AC-3,4,5,6,7,9.
- `tests/Feature/Filament/Students/ImportStudentsTest.php` (Livewire `ImportAction` + `Importer::test()`/`ImportAction::fake`): AC-1,2,8,10,11 (queue `sync` dan `Queue::fake`/`queue:work --stop-when-empty` untuk database).
- `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (perbarui): AC-12; `tests/Feature/Console/ScheduleTest` bila sudah ada, kalau tidak assert di test AC-12.

## Urutan implementasi
Paket 1 (data + Action; hijau: `ImportStudentRowTest`, `RolePermissionSeederTest`):
1. Migrasi publish imports/failed_import_rows + notifications.
2. Permission `import:student` + policy + seeder.
3. `ImportStudentRow` (jenjang, cakupan, cohort, upsert, rombel, wali, normalisasi telepon).
Paket 2 (Filament; hijau: `ImportStudentsTest`):
4. `StudentImporter` (kolom, contoh, pesan, notifikasi, formula injection).
5. `ImportAction` di `ListStudents`, `databaseNotifications()` di panel, teks `lang/id`.
Paket 3: dokumen `docs/erd.md` (catatan tabel import) dan `docs/prd.md` bila perlu; Pint; cek tampilan modal 360px/desktop (design-guide §0).

## Risiko & pertanyaan terbuka
- Risiko: `ImportCsv` membungkus seluruh chunk (100 baris) dalam satu `DB::transaction`; error per baris ditangkap, jadi Action memakai savepoint (`DB::transaction` bersarang) agar rollback hanya satu baris.
- Risiko: UnitScope di queue bergantung `auth()->setUser($import->user)` (sudah dilakukan `ImportCsv`); test memverifikasi, termasuk lewat `queue:work`.
- Pertanyaan 1: tanggal `entered_on` default 1 Juli tahun masuk, atau perlu kolom `entry_date` opsional di template? Rekomendasi: default saja (YAGNI).
- Pertanyaan 2: NIS beda jenjang dengan data lama gagal (bukan memindahkan). Rekomendasi: ya, gagal.
- Pertanyaan 3: batas `maxRows(2000)` per file; rekomendasi cukup untuk ratusan santri, bagi file bila lebih.

## Riwayat revisi
(belum ada)

## Review #1 — 2026-10-19

Verdict: APPROVED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Action butir 6 | `firstOrCreate(name, phone_normalized)` tidak cocok dengan wali yang dibuat manual via Filament dengan telepon tak ternormalisasi (`+62 812-…`), sehingga wali ganda. | Bandingkan terhadap telepon ternormalisasi (query lalu filter di PHP per nama) atau catat sebagai batasan di rencana. |
| 2 | minor | Action butir 6 | `guardian1_billing=tidak` bertabrakan dengan aturan `AttachGuardian` (wali pertama selalu kontak tagihan); bila wali 1 dan 2 sama-sama `ya`, hasil bergantung urutan `SetBillingContact`. | Tetapkan aturan eksplisit di AC-5 (wali 1 selalu tagih; `ya` pada wali 2 menggantikan) dan uji. |
| 3 | minor | Action butir 4 | Re-import dengan `relation` berbeda tidak memperbarui pivot (`syncWithoutDetaching` pada pivot yang sudah ada). Santri tak aktif yang cocok NIS gagal di `EnrollStudent` hanya bila ada rombel. | Putuskan saat implementasi; cukup catat. |
| 4 | minor | Importer | `Unit.name` tidak unik (hanya `code`); pencocokan nama bisa ambigu. Migrasi publish harus bertanggal setelah migrasi terakhir. `Importer::test()` belum diverifikasi ada di v5. | Kode didahulukan, nama ambigu = gagal. Cek API test saat RED. |
| 5 | minor | Lingkup | Body issue masih memuat AC NIK/KK. Terverifikasi: `students`/`guardians` tanpa kolom NIK/KK (hanya `documents.number`, erd.md baris ~401). | Perbarui body issue #27 seperti rencana. |

Terverifikasi benar: `ImportCsv` memanggil `auth()->setUser()` sebelum importer (UnitScope berlaku di queue) dan membungkus chunk dalam satu `DB::transaction` (savepoint per baris tepat); `RowImportFailedException`/`ValidationException` masuk baris gagal; `shouldPreventFormulaInjection()` ada (default false); `withoutUnitScope()` ada di `HasUnitScope`; `EXTRA_RESOURCE_ACTIONS` ada di `RolePermissionSeeder`; scheduler sudah menjalankan `queue:work --stop-when-empty`; `nis` unik global. Ukuran dan YAGNI wajar (12 AC, ±85 baris).
