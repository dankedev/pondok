# F027 rapor-diniyah — Rapor diniyah

Issue: #8
Status rencana: APPROVED (review #1, 2026-10-26)
Jalur: penuh (migration, permission, portal wali)

## Ringkasan & tujuan
Wali kelas mengisi nilai mapel diniyah per santri per semester dan catatan wali kelas; sistem menghitung predikat dari rentang nilai dan mencetak rapor satu santri. Wali melihat dan mencetak rapor anaknya di portal wali. Cakupan keputusan user 26 Okt 2026: nilai per mapel + rapor cetak.

## Ruang lingkup
- Termasuk: mapel diniyah per jenjang (aktif/nonaktif), nilai 0-100 per semester, predikat, rata-rata sederhana, catatan wali kelas per semester, halaman cetak rapor per santri (admin dan portal wali).
- Tidak termasuk (YAGNI): peringkat, bobot/komponen nilai (UTS/UAS), integrasi kehadiran, mapel per tingkat atau penugasan ustadz per mapel, ambang predikat yang bisa diatur, alur terbit/persetujuan rapor, tanda tangan F063, impor Excel, rapor massal satu rombel, pustaka PDF baru, kode perbaikan data lama.

## Keputusan desain
- **Modul: Akademik** (bukan modul baru). Mapel/nilai adalah data akademik santri, memakai `classrooms`, `enrollments`, `staff`; tidak punya saklar sendiri dan tidak berdiri tanpa Akademik. `Module` tidak berubah. Portal wali ikut `PortalWali` + `Akademik` (disaring di Action portal).
- **"PDF" = halaman cetak A4** (Blade mandiri, pola `PrintIssuedLetter`, design-guide §11) yang disimpan sebagai PDF lewat dialog cetak peramban. Repo belum punya pustaka PDF dan tanpa izin tidak boleh menambah dependensi; pendekatan ini jalan di cPanel tanpa Node. Lihat pertanyaan terbuka 1.
- **Predikat tidak disimpan**, dihitung dari `score` oleh enum `App\Enums\DiniyahPredicate::fromScore()`: Mumtaz 90-100, Jayyid Jiddan 80-89, Jayyid 70-79, Maqbul 60-69, Rasib 0-59. Rentang tetap di enum (bukan setting).
- Nilai kosong = tidak ada baris. Mengosongkan sel menghapus baris nilai (bukan data keuangan; boleh).
- Rata-rata = rerata aritmetika nilai terisi, dibulatkan 1 desimal saat tampil; tidak disimpan, tidak dipakai untuk peringkat.
- Semester adalah `tinyint` 1 atau 2 per tahun ajaran (tidak ada tabel semester).

## Model data
Perubahan `docs/erd.md` (bagian Akademik & Santri; ditulis green-coder pada paket 1):
```
diniyah_subjects { bigint id PK; bigint unit_id FK; string name; tinyint sort_order; boolean is_active; timestamps }
diniyah_grades { bigint id PK; bigint student_id FK; bigint academic_year_id FK; tinyint semester "1|2"; bigint diniyah_subject_id FK; unsignedTinyInteger score "0-100"; bigint recorded_by FK users; timestamps }
diniyah_report_notes { bigint id PK; bigint student_id FK; bigint academic_year_id FK; tinyint semester; text note; bigint recorded_by FK users; timestamps }
```
- Relasi: `units 1-n diniyah_subjects`; `students 1-n diniyah_grades`; `academic_years 1-n diniyah_grades`; `diniyah_subjects 1-n diniyah_grades` (`restrictOnDelete`, mapel tidak dihapus, hanya `is_active=false`); `students/academic_years 1-n diniyah_report_notes`. FK santri dan tahun ajaran `restrictOnDelete`; `recorded_by` `nullOnDelete` (kolom nullable).
- Constraint (tambah ke tabel constraint ERD): `diniyah_subjects` unique (`unit_id`, `name`); `diniyah_grades` unique (`student_id`, `academic_year_id`, `semester`, `diniyah_subject_id`); `diniyah_report_notes` unique (`student_id`, `academic_year_id`, `semester`). Semua kolom unique tidak nullable, jadi tidak perlu generated column.
- Tanpa soft delete. `score` integer (cast `integer`); CHECK 0-100 dijaga Action (SQLite/MySQL/Postgres kompatibel tanpa CHECK).
- Aturan: nilai dan catatan hanya untuk santri yang punya `enrollments` pada tahun ajaran itu; rombel diturunkan dari enrollment (tanpa kolom `classroom_id`). Seed demo: mapel contoh per jenjang (Aqidah, Fiqih, Hadits, Nahwu, Sharaf, Bahasa Arab, Akhlak) dan nilai demo, tanpa konten di luar manhaj.

## Action class (`app/Actions/Akademik/`)
- `SaveDiniyahGrades(User $user, Classroom $classroom, int $semester, array $scores, array $notes)`: `$scores` = `[student_id => [subject_id => int|null]]`, `$notes` = `[student_id => string|null]`. Satu transaksi: validasi semua dulu (semester 1|2; santri ada di enrollment rombel tahun itu; mapel aktif dan satu jenjang dengan rombel; nilai bilangan bulat 0-100), lalu upsert nilai dan catatan; `null`/kosong menghapus baris. Idempotent (panggil dua kali = hasil sama, `updated_at` tak berubah bila nilai sama). Gagal validasi = `ValidationException`, tidak ada yang tersimpan. Cek `Gate` input nilai dan rombel dalam `AttendanceSession::visibleClassrooms($user)` (dipakai ulang; bila perlu pindahkan ke `Classroom` sebagai satu-satunya sumber). Return jumlah nilai dan catatan tersimpan.
- `BuildDiniyahReportCard(Student $student, AcademicYear $year, int $semester)`: baca-saja; return array identitas, rombel, wali kelas (`homeroomStaff`), baris `[mapel, nilai, predikat]` urut `sort_order` (mapel aktif atau yang sudah bernilai), rata-rata, catatan. Dipakai bersama oleh cetak admin dan portal (DRY).
- `SaveDiniyahSubject` tidak dibuat: resource Filament memakai CRUD bawaan (tanpa aturan selain unique).

## UI
- Grup navigasi Akademik. Resource `DiniyahSubjects` (`BelongsToModule` Akademik; buat/ubah, aktifkan/nonaktifkan, tanpa hapus; kolom jenjang, nama, urutan, status). Page `DiniyahGradeEntry` (`getModule()` Akademik): pilih rombel (bawaan tahun ajaran aktif) dan semester; lembar santri x mapel aktif dengan isian angka plus catatan wali kelas per santri; tombol Simpan memanggil Action; tautan "Cetak rapor" per santri. Lembar harus terbaca di 360px (kartu per santri atau gulir horizontal) dan menampilkan state kosong ("Belum ada mapel aktif untuk jenjang ini").
- Route cetak admin `akademik/rapor/{student}/{year}/{semester}` (auth + `EnsureModuleIsEnabled:akademik`, controller baru `Akademik\PrintDiniyahReport`, view A4 hitam-putih, kop dari `settings`, tanpa aset eksternal, design-guide §11). Portal wali: bagian "Rapor diniyah" di `ShowStudent` (semester yang punya nilai, hanya bila Akademik aktif) dan route `wali/santri/{student}/rapor/{year}/{semester}` (404 bila bukan anak wali).
- Teks di `lang/id/pondok.php`. Baca design-guide §0, §5 (format angka), §7, §11 dan panggil `/anti-ui-slop`.

## Permission & cakupan
- Resource `DiniyahSubject`: `view-any/view/create/update` ke `admin-sekolah` dan `kesantrian`; `super-admin` otomatis. Permission khusus `input-diniyah-grades` (wali-kelas, admin-sekolah) dan `view-diniyah-report` (wali-kelas, admin-sekolah, kesantrian). `wali` tanpa permission admin; akses lewat kepemilikan santri.
- Cakupan jenjang: `unit_user` menyaring mapel (`unit_id`) dan rombel; santri lewat global scope `Student`. Wali-kelas hanya rombel yang ia walikan; admin-sekolah rombel dalam cakupan jenjangnya. Akademik mati: resource/page/route admin dan portal tersembunyi, data tetap.

## Kriteria penerimaan
1. Mapel: admin-sekolah/kesantrian membuat mapel per jenjang; nama sama di jenjang sama ditolak, jenjang lain boleh; mapel nonaktif tidak punya aksi hapus dan hilang dari lembar input; wali-kelas tidak bisa membuat/mengubah mapel; pengguna ber-`unit_user` hanya melihat mapel jenjangnya.
2. `DiniyahPredicate::fromScore`: tiap batas (0, 59, 60, 69, 70, 79, 80, 89, 90, 100) memetakan ke predikat yang benar; nilai di luar 0-100 tidak valid.
3. `SaveDiniyahGrades` menyimpan nilai dan catatan satu rombel-semester; baris tersimpan dengan `recorded_by` pengguna.
4. Idempotent: simpan data yang sama dua kali menghasilkan jumlah baris sama (satu baris per santri-semester-mapel), dan mengubah nilai memperbarui baris yang sama.
5. Mengosongkan sel menghapus baris nilai; catatan kosong menghapus catatan; nilai semester lain tidak terpengaruh.
6. Validasi atomik: nilai -1, 101, 85.5, atau teks; semester 3; mapel nonaktif; mapel jenjang lain; santri bukan anggota rombel (atau enrollment tahun lain) menolak seluruh simpanan dan tidak ada baris yang tersimpan.
7. Otorisasi input: wali-kelas hanya menyimpan untuk rombel yang ia walikan (rombel lain ditolak); admin-sekolah untuk rombel dalam cakupan jenjangnya; kesantrian, musyrif, `wali` ditolak; page tersembunyi tanpa permission.
8. `BuildDiniyahReportCard` mengembalikan identitas, rombel tahun itu, wali kelas, baris mapel urut `sort_order` dengan predikat, rata-rata (nilai terisi saja; tanpa nilai = rata-rata kosong, bukan 0), dan catatan; mapel nonaktif yang sudah bernilai tetap tampil; tidak ada kolom peringkat.
9. Cetak admin: pemegang `view-diniyah-report` untuk santri dalam cakupannya mendapat halaman 200 berisi nama, mapel, nilai, predikat, catatan, dan wali kelas; wali-kelas rombel lain, santri di luar cakupan jenjang, dan pengguna tanpa permission ditolak (403/404).
10. Portal wali: wali melihat bagian rapor di rincian anaknya dan membuka rapor semester yang bernilai; rapor santri milik wali lain 404; semester tanpa nilai 404; wali tidak melihat tautan/route admin.
11. Modul: Akademik mati menyembunyikan resource, page, route cetak admin, dan bagian portal (404) tanpa menghapus data; `PortalWali` mati membuat route portal 404; `ModuleComplianceTest` lulus tanpa pengecualian baru.
12. Riwayat: nilai tahun ajaran lama tetap terbaca setelah santri naik kelas (enrollment baru) dan setelah rombel tahun itu bukan lagi aktif; menghapus mapel/santri/tahun ajaran yang punya nilai ditolak FK (`restrictOnDelete`).
13. Skema: migration berjalan di SQLite (dan kompatibel MySQL/Postgres); unique key tiga tabel menolak duplikat di level database.

## Rencana test
- `tests/Feature/Akademik/DiniyahSubjectResourceTest.php`: AC-1, AC-12 (FK mapel).
- `tests/Unit/DiniyahPredicateTest.php`: AC-2 (dataset batas).
- `tests/Feature/Akademik/SaveDiniyahGradesTest.php`: AC-3, 4, 5, 6, 7 (Action), 13, 12 (riwayat).
- `tests/Feature/Akademik/DiniyahGradeEntryPageTest.php`: AC-7 (page, permission), simpan lewat Livewire.
- `tests/Feature/Akademik/BuildDiniyahReportCardTest.php`: AC-8.
- `tests/Feature/Akademik/PrintDiniyahReportTest.php`: AC-9.
- `tests/Feature/PortalWali/DiniyahReportTest.php`: AC-10.
- `tests/Feature/ModuleComplianceTest.php` (sudah ada) + satu test saklar di file Print/Portal: AC-11.

## Urutan implementasi
Paket 1, data + Action (hijau: `SaveDiniyahGrades`, `BuildDiniyahReportCard`, `DiniyahPredicate` test):
1. Migration tiga tabel, model, factory, enum `DiniyahPredicate`, update `docs/erd.md`.
2. Permission di `RolePermissionSeeder` (+ peran), `SaveDiniyahGrades`, `BuildDiniyahReportCard`; seed demo mapel.

Paket 2, Filament (hijau: `DiniyahSubjectResourceTest`, `DiniyahGradeEntryPageTest`, `ModuleComplianceTest`):
3. Resource `DiniyahSubjects`, lang `id`.
4. Page `DiniyahGradeEntry`, navigasi, tautan cetak.

Paket 3, cetak + portal (hijau: `PrintDiniyahReportTest`, `DiniyahReportTest`):
5. Controller + view cetak A4 admin, route.
6. Bagian rapor di portal wali, route, filter modul; cek tampilan 360px/1024px/desktop dan cetak A4.

## Risiko & pertanyaan terbuka
1. **Format PDF:** apakah halaman cetak A4 (simpan PDF lewat peramban) cukup, atau perlu berkas PDF yang dihasilkan server (butuh izin menambah pustaka PHP murni seperti dompdf)? Rencana ini memakai halaman cetak.
2. **Terbit rapor:** wali melihat nilai begitu disimpan (bisa terlihat sebelum final). Perlu tombol "terbitkan semester" (kolom `published_at`)? Rencana ini tidak memakainya (YAGNI).
3. **Ustadz mapel:** belum ada peran ustadz diniyah dan penugasan mapel; hanya wali-kelas (dan admin-sekolah) yang menginput semua mapel rombelnya. Cukup?
4. Ambang predikat dan istilahnya (Mumtaz...Rasib) perlu dikonfirmasi lembaga; tetap di enum.
5. Rata-rata di rapor termasuk, tetapi bukan peringkat; tolak bila dianggap di luar cakupan.
6. Penandatangan wali kelas di cetak memakai nama `homeroomStaff` saja, bukan penandatangan F063.

## Riwayat revisi
(belum ada)

## Review #1 — 2026-10-26

Verdict: APPROVED

Keputusan orkestrator atas pertanyaan terbuka 1-6 (cetak A4 Blade, tanpa terbit, wali-kelas dan admin-sekolah mengisi semua mapel, ambang enum, penandatangan = wali kelas) diterima dan konsisten dengan YAGNI serta batasan cPanel. Bagian "Risiko & pertanyaan terbuka" perlu diubah menjadi "Keputusan" saat implementasi.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | `BuildDiniyahReportCard` / AC-8, AC-12 | "Mapel aktif" tidak menyebut jenjang mana. Setelah santri pindah jenjang (`students.unit_id` berubah, ERD baris 171), rapor tahun lama salah bila memakai `unit_id` santri sekarang. Mapel yang ditambah belakangan juga muncul sebagai baris kosong di rapor lama. | Pakai `classrooms.unit_id` dari enrollment tahun itu. Tambah satu assert di AC-8 atau AC-12 untuk santri yang pindah jenjang. |
| 2 | minor | `SaveDiniyahGrades` / lembar input | Roster tidak didefinisikan (ERD baris 419 memakai santri `aktif` untuk absensi). Belum jelas apakah tahun ajaran non-aktif boleh diedit. | Samakan dengan roster absensi (santri `aktif`, `enrollments.classroom_id`). Putuskan saat implementasi, cukup satu kalimat di rencana. |
| 3 | minor | `visibleClassrooms` | Setelah dipakai F027, ada dua pemakai nyata (absensi dan rapor). Frasa "bila perlu pindahkan" ambigu. | Pindahkan ke `Classroom` dengan delegasi dari `AttendanceSession`, atau tetap panggil di tempat. Pilih satu, jangan menggandakan. Otorisasi cetak admin (AC-9) memakai sumber yang sama. |
| 4 | minor | AC-12 | "Menghapus santri ditolak FK" tidak berarti: `students` soft delete, hanya `forceDelete` yang kena FK. | Batasi test pada mapel dan tahun ajaran. |
| 5 | minor | Cetak/portal | Perilaku saat santri tidak punya enrollment di tahun itu belum ditulis (AC-9/10 hanya menyebut semester tanpa nilai). | Satu kalimat: tanpa enrollment atau tanpa nilai dan catatan = 404. |
| 6 | minor | Permission | Permission baru (`input-diniyah-grades`, `view-diniyah-report`, `DiniyahSubject`) hanya sampai ke instalasi berjalan lewat `RolePermissionSeeder`. Pondok sudah dirilis. | Ikuti cara F025 (AttendanceSession) memberi permission ke instalasi lama; jangan buat kode migrasi khusus. |
| 7 | minor | Paket kerja | Paket 3 (cetak + portal) memuat dua route dan dua view. | Tampilan cetak dipakai bersama lewat satu partial Blade (DRY), view portal hanya memanggil `BuildDiniyahReportCard`. |

Ringkasan: 0 blocker, 0 major, 7 minor. Jumlah AC (13), panjang rencana (±96 baris), pemetaan test, konvensi data (tanpa soft delete, `restrictOnDelete`, unique tanpa nullable), dan kepatuhan modul sudah memadai.
