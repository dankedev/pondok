# F023 tahfidz — Tahfidz

Issue: #4
Status rencana: APPROVED (orkestrator, setelah revisi Review #1)

## Ringkasan & tujuan
Modul `tahfidz` (PRD §3.8, ERD "Tahfidz"), satu PR (keputusan orkestrator):
- halaqah per tahun ajaran dengan pengampu dan anggota yang berriwayat;
- setoran ziyadah/murajaah/tasmi' berbasis rentang surah–ayat;
- `student_juz_progress` yang dihitung ulang oleh Action;
- ujian (kenaikan juz, tasmi' 5 juz, khataman) dengan nomor syahadah;
- target hafalan per jenjang/tingkat.

## Ruang lingkup
- **Termasuk:** modul + saklar, 6 tabel, 4 resource Filament, Action, permission + visibilitas + cakupan jenjang, demo, `lang/id`, design-guide §4, pembaruan ERD.
- **Tidak termasuk** (usulan issue terpisah):
  - progres hafalan di portal wali, setelah portal wali dibangun ulang sebagai Blade+Livewire; tidak menyentuh `app/Filament/Wali`;
  - widget dashboard tahfidz;
  - cetak syahadah PDF/QR;
  - prestasi otomatis dari khataman;
  - peta halaman mushaf / `pages` otomatis;
  - `target_pages_weekly`;
  - grafik;
  - edit setoran/ujian (koreksi = hapus lalu catat ulang).

## Keputusan
- **D1 (disetujui):** data Al-Qur'an minimum = `ayah_count` 114 surah (Hafs, penomoran mushaf Madinah) + peta awal 30 juz, keduanya dikunci AC-2. `pages` diisi penyimak (desimal satu angka). Usulan issue: seed 604 awal halaman mushaf Madinah 15 baris dari Tanzil quran-data (CC BY 3.0), lalu `pages` otomatis.
- **D2:** satu setoran boleh lintas surah asal maju (indeks global `from` ≤ `to`). Hafalan mundur dicatat sebagai beberapa setoran.
- **D3:** capaian juz hanya dari `ziyadah` dengan nilai selain `ulang`, ditambah ujian `lulus`.
- **D4 (disetujui):** halaqah dan anggotanya hanya dikelola pemegang `update:halaqah` (admin-sekolah, super-admin). Ustadz-tahfidz melihat halaqah yang ia ampu, lalu mencatat setoran dan ujian untuk anggotanya.
- **D5:** tidak ada baris `modules.tahfidz.enabled` berarti modul mati.
  - Instalasi baru: `SettingSeeder` membuat baris `true`.
  - Upgrade: `pondok:upgrade` (`SystemSeeder`) hanya membuat baris bila belum ada. Baris `false` yang sudah ada (seeder sebelum F066 atau dimatikan super-admin) tidak ditimpa; super-admin menyalakannya manual di Pengaturan → Modul.
  - Tidak ada migrasi data.
- **D6:** `certificate_no` manual, opsional, hanya untuk hasil `lulus`, unik.
- **D7:** `tahfidz_exams.halaqah_id` (halaqah santri saat ujian), agar visibilitas ujian sama dengan setoran.

## Model data (satu migration per tabel; tanpa soft delete)
**`quran_surahs`**
- Kolom: `id` smallint PK 1–114 (bukan autoincrement), `name`, `ayah_count`, `juz_start`. `name_arabic` dibuang (YAGNI).
- 114 baris ditulis di migration, sehingga ikut `migrate` di instance lama.
- Data `no Nama ayat` (transliterasi umum Indonesia; nama dicek orkestrator saat review PR; sudah dihitung Σ 6236 dan cocok dengan AC-2):
  1 Al-Fatihah 7 · 2 Al-Baqarah 286 · 3 Ali 'Imran 200 · 4 An-Nisa' 176 · 5 Al-Ma'idah 120 · 6 Al-An'am 165 · 7 Al-A'raf 206 · 8 Al-Anfal 75 · 9 At-Taubah 129 · 10 Yunus 109 · 11 Hud 123 · 12 Yusuf 111 · 13 Ar-Ra'd 43 · 14 Ibrahim 52 · 15 Al-Hijr 99 · 16 An-Nahl 128 · 17 Al-Isra' 111 · 18 Al-Kahf 110 · 19 Maryam 98 · 20 Taha 135 · 21 Al-Anbiya' 112 · 22 Al-Hajj 78 · 23 Al-Mu'minun 118 · 24 An-Nur 64 · 25 Al-Furqan 77 · 26 Asy-Syu'ara' 227 · 27 An-Naml 93 · 28 Al-Qasas 88 · 29 Al-'Ankabut 69 · 30 Ar-Rum 60 · 31 Luqman 34 · 32 As-Sajdah 30 · 33 Al-Ahzab 73 · 34 Saba' 54 · 35 Fatir 45 · 36 Yasin 83 · 37 As-Saffat 182 · 38 Sad 88 · 39 Az-Zumar 75 · 40 Gafir 85 · 41 Fussilat 54 · 42 Asy-Syura 53 · 43 Az-Zukhruf 89 · 44 Ad-Dukhan 59 · 45 Al-Jasiyah 37 · 46 Al-Ahqaf 35 · 47 Muhammad 38 · 48 Al-Fath 29 · 49 Al-Hujurat 18 · 50 Qaf 45 · 51 Az-Zariyat 60 · 52 At-Tur 49 · 53 An-Najm 62 · 54 Al-Qamar 55 · 55 Ar-Rahman 78 · 56 Al-Waqi'ah 96 · 57 Al-Hadid 29 · 58 Al-Mujadalah 22 · 59 Al-Hasyr 24 · 60 Al-Mumtahanah 13 · 61 As-Saff 14 · 62 Al-Jumu'ah 11 · 63 Al-Munafiqun 11 · 64 At-Tagabun 18 · 65 At-Talaq 12 · 66 At-Tahrim 12 · 67 Al-Mulk 30 · 68 Al-Qalam 52 · 69 Al-Haqqah 52 · 70 Al-Ma'arij 44 · 71 Nuh 28 · 72 Al-Jinn 28 · 73 Al-Muzzammil 20 · 74 Al-Muddassir 56 · 75 Al-Qiyamah 40 · 76 Al-Insan 31 · 77 Al-Mursalat 50 · 78 An-Naba' 40 · 79 An-Nazi'at 46 · 80 'Abasa 42 · 81 At-Takwir 29 · 82 Al-Infitar 19 · 83 Al-Mutaffifin 36 · 84 Al-Insyiqaq 25 · 85 Al-Buruj 22 · 86 At-Tariq 17 · 87 Al-A'la 19 · 88 Al-Gasyiyah 26 · 89 Al-Fajr 30 · 90 Al-Balad 20 · 91 Asy-Syams 15 · 92 Al-Lail 21 · 93 Ad-Duha 11 · 94 Asy-Syarh 8 · 95 At-Tin 8 · 96 Al-'Alaq 19 · 97 Al-Qadr 5 · 98 Al-Bayyinah 8 · 99 Az-Zalzalah 8 · 100 Al-'Adiyat 11 · 101 Al-Qari'ah 11 · 102 At-Takasur 8 · 103 Al-'Asr 3 · 104 Al-Humazah 9 · 105 Al-Fil 5 · 106 Quraisy 4 · 107 Al-Ma'un 7 · 108 Al-Kausar 3 · 109 Al-Kafirun 6 · 110 An-Nasr 3 · 111 Al-Lahab 5 · 112 Al-Ikhlas 4 · 113 Al-Falaq 5 · 114 An-Nas 6.
- `QuranSurah` (read-only), konstanta `JUZ_STARTS` (juz → [surah, ayat]):
  `1:[1,1] 2:[2,142] 3:[2,253] 4:[3,93] 5:[4,24] 6:[4,148] 7:[5,82] 8:[6,111] 9:[7,88] 10:[8,41] 11:[9,93] 12:[11,6] 13:[12,53] 14:[15,1] 15:[17,1] 16:[18,75] 17:[21,1] 18:[23,1] 19:[25,21] 20:[27,56] 21:[29,46] 22:[33,31] 23:[36,28] 24:[39,32] 25:[41,47] 26:[46,1] 27:[51,31] 28:[58,1] 29:[67,1] 30:[78,1]`.
- Helper statis: `globalIndex(surah, ayah)` (1–6236, kumulatif `ayah_count`, dimuat sekali per request) dan `juzRange(juz)`.

**Tabel lain**
- **`halaqahs`:**
  - kolom: `academic_year_id` FK restrict, `unit_id` FK nullable restrict (null = lintas jenjang), `teacher_staff_id` FK restrict, `name`, `gender` L|P, `schedule` nullable, `is_active`;
  - unique (`academic_year_id`,`name`).
- **`halaqah_members`:**
  - kolom: `halaqah_id` FK restrict, `student_id` FK cascade, `joined_on`, `left_on` nullable;
  - `active_marker` storedAs `CASE WHEN left_on IS NULL THEN 1 END`, pola `room_assignments`;
  - unique (`student_id`,`active_marker`); index (`student_id`,`left_on`);
  - interval keanggotaan `[joined_on, left_on)`.
- **`memorization_records`:**
  - FK: `student_id` cascade; `halaqah_id`, `listener_staff_id`, `from_surah_id`, `to_surah_id` restrict;
  - `recorded_on`, `session` subuh|pagi|sore|maghrib|malam, `type` ziyadah|murajaah|tasmi, `from_ayah`/`to_ayah` smallint;
  - `pages` decimal(5,1) cast `decimal:1`;
  - `grade` mumtaz|jayyid_jiddan|jayyid|maqbul|ulang, `mistakes` unsignedTinyInteger, `note` nullable;
  - `recorded_by` FK users nullOnDelete;
  - index (`student_id`,`recorded_on`), (`halaqah_id`,`recorded_on`).
- **`student_juz_progress`:** PK (`student_id`,`juz`), `student_id` cascade; `status` proses|hafal|lulus_ujian (belum = tidak ada baris); `completed_on` nullable. Hanya ditulis `RefreshJuzProgress`.
- **`tahfidz_targets`:** `academic_year_id`, `unit_id` FK restrict; `grade` nullable (null = semua tingkat); `target_juz` decimal(4,1); unique (`academic_year_id`,`unit_id`,`grade_key` = generated `COALESCE(grade,0)`).
- **`tahfidz_exams`:**
  - FK: `student_id` cascade; `halaqah_id`, `examiner_staff_id` restrict;
  - `kind` kenaikan_juz|tasmi_5_juz|khataman, `juz_from`/`juz_to`, `exam_on`;
  - `score` 0–100, `result` lulus|ulang, `certificate_no` nullable unique.

**Relasi dan scope**
- Relasi: `User::staff()` hasOne, `Student::juzProgress()`, `Student::halaqahMembers()`.
- `HasUnitScope`:
  - `Halaqah`: `unit_id` dalam cakupan **atau** null;
  - `HalaqahMember`, `MemorizationRecord`, `TahfidzExam`: lewat santri (pola `RoomAssignment::applyUnitScope`);
  - `TahfidzTarget`: lewat `unit_id`.
- Visibilitas (satu definisi):
  - `Halaqah::scopeVisibleTo(User)`: pemegang `update:halaqah` semua; selain itu `teacher_staff_id` = `User::staff()`;
  - `MemorizationRecord::scopeVisibleTo` dan `TahfidzExam::scopeVisibleTo` = `whereIn('halaqah_id', Halaqah::visibleTo($user)->select('id'))`;
  - policy `view`/`delete` per record = permission **dan** record ada di `visibleTo`.

## Action class (`app/Actions/Tahfidz/`)
Semua Action menerima `User $actor`, cek permission + visibilitas di awal (`AuthorizationException`), dan gagal validasi dengan `ValidationException` per field.

- **`AddHalaqahMember(actor, halaqah, student, joinedOn)`**
  - butuh `update:halaqah`;
  - syarat: halaqah aktif; santri aktif; gender sama; jenjang sama bila `unit_id` terisi; `joinedOn` dalam tahun ajaran halaqah dan ≤ hari ini;
  - keanggotaan aktif lain ditutup `left_on = joinedOn` (tolak bila < `joined_on` lama);
  - sudah aktif di halaqah yang sama → kembalikan baris yang ada;
  - transaksi + `lockForUpdate` santri.
- **`EndHalaqahMembership(actor, member, leftOn)`**
  - butuh `update:halaqah`;
  - syarat: belum ditutup; `joined_on` ≤ `leftOn` ≤ hari ini (`leftOn = joined_on` tercatat).
- **`RecordMemorization(actor, data)`**
  - butuh `create:memorization-record`;
  - halaqah aktif dan `visibleTo(actor)`;
  - santri punya keanggotaan di **`halaqah_id` yang dikirim** yang meliputi `recorded_on`, dan `recorded_on` ≤ hari ini;
  - range: surah 1–114; ayat 1..`ayah_count`; indeks `from` ≤ `to`;
  - penyimak staff aktif (bawaan `actor->staff`);
  - `pages` 0,1–604; `mistakes` 0–255;
  - simpan `recorded_by`, lalu refresh.
- **`DeleteMemorizationRecord(actor, record)`:** butuh `delete:memorization-record` + `visibleTo`; hapus, lalu refresh.
- **`RecordTahfidzExam(actor, data)`**
  - butuh `create:tahfidz-exam`;
  - halaqah aktif `visibleTo(actor)`, santri anggota halaqah itu pada `exam_on`, dan `exam_on` ≤ hari ini;
  - juz 1–30, `from` ≤ `to`; `kenaikan_juz` from = to; `tasmi_5_juz` to − from = 4; `khataman` 1–30;
  - skor 0–100; `certificate_no` hanya bila `lulus`, unik; penguji staff aktif;
  - simpan, lalu refresh.
- **`DeleteTahfidzExam(actor, exam)`:** butuh `delete:tahfidz-exam` + `visibleTo`; hapus, lalu refresh.
- **`RefreshJuzProgress(student)`**
  - `DB::transaction` + `lockForUpdate` santri; hitung penuh, idempoten;
  - baca kolom minimum ziyadah non-`ulang` dan ujian lulus;
  - per juz:
    - `lulus_ujian` bila ujian lulus mencakupnya (`completed_on` = tanggal hafal bila ada, selain itu `exam_on` pertama);
    - `hafal` bila rentang gabungan menutup `juzRange` (`completed_on` = `recorded_on` saat tertutup, urut tanggal lalu id);
    - `proses` bila sebagian;
    - selain itu baris dihapus.
- **`ResolveTahfidzTarget(student)`:** tahun ajaran aktif + jenjang santri + tingkat rombel aktif → fallback `grade` null → null.

## UI (Filament, grup "Tahfidz", ikon `BookOpen`, semua `BelongsToModule` → `tahfidz`)
`getEloquentQuery()`, relation manager, dan setiap opsi Select memakai `visibleTo(auth user)`.

- **`HalaqahResource`:**
  - list/create/edit, tanpa hapus (cukup nonaktifkan);
  - RelationManager "Anggota": aksi Tambah/Keluarkan hanya bagi `update:halaqah`;
  - kolom: santri, masuk, keluar, "Juz hafal tahun ini" (hafal + lulus ujian dengan `completed_on` dalam tahun ajaran halaqah), "Target", badge `warning` "Di bawah target";
  - tanpa N+1: eager load `student.currentEnrollment.classroom`, `withCount` progres, target di-memo per (jenjang, tingkat).
- **`MemorizationRecordResource` ("Setoran"):**
  - kolom: tanggal, santri, halaqah, jenis, rentang ("Al-Baqarah 1–5" / "An-Naba' 1 – An-Nazi'at 46"), halaman, nilai, kesalahan;
  - filter: halaqah, jenis, tanggal;
  - aksi Catat setoran (santri bergantung halaqah) dan Hapus dengan konfirmasi.
- **`TahfidzExamResource` ("Ujian"):** list, Catat, Hapus.
- **`TahfidzTargetResource` ("Target hafalan"):** CRUD biasa, rule unik null-aware.
- **Badge** (tambah ke design-guide §4, mapping di satu tempat):
  - juz: `proses` warning, `hafal` info, `lulus_ujian` success, belum gray;
  - ujian: `lulus` success, `ulang` warning.
- **Lainnya:** tanggal §5, empty state §7, teks `lang/id/pondok.php` (`tahfidz.*`, `modules.tahfidz.*`), cek 360/1024px, kontrak §13 di PR.

## Permission & cakupan
- `RESOURCE_MODELS` ditambah `Halaqah`, `MemorizationRecord`, `TahfidzExam`, `TahfidzTarget`.
- **admin-sekolah:** Halaqah tanpa delete; MemorizationRecord & TahfidzExam viewAny/view/create/delete; TahfidzTarget penuh.
- **ustadz-tahfidz** (arm sendiri, dipisah dari `petugas-pos`): `view-dashboard`, Halaqah viewAny/view, MemorizationRecord viewAny/view/create/delete, TahfidzExam viewAny/view/create.
- **super-admin:** semua.
- `Module::Tahfidz = 'tahfidz'`, `requires()` = [Akademik].

## Kriteria penerimaan
- **AC-1 Modul:**
  - `Module::Tahfidz` dengan label/deskripsi `lang/id`, requires [Akademik];
  - 4 resource ber-`getModule()` `tahfidz`;
  - modul mati → resource tersembunyi/404, data tetap;
  - `SettingSeeder` membuat 9 saklar;
  - baris `false` yang disisipkan manual tidak ditimpa seed ulang; tanpa baris → `isEnabled()` false.
- **AC-2 Data Al-Qur'an:**
  - 114 surah, Σ`ayah_count` = 6236;
  - sampel (1 Al-Fatihah 7, 2 Al-Baqarah 286, 9 At-Taubah 129, 26 Asy-Syu'ara' 227, 112 Al-Ikhlas 4, 114 An-Nas 6);
  - ayat awal tiap `JUZ_STARTS` ≤ `ayah_count`;
  - jumlah ayat per juz dari `ayah_count` + peta juz persis `[148,111,126,131,124,110,149,142,159,127,151,170,154,227,185,269,190,202,339,171,178,169,357,175,246,195,399,137,431,564]`;
  - `juz_start` = juz yang memuat ayat 1; `globalIndex(114,6)` = 6236.
- **AC-3 Halaqah:**
  - validasi staff aktif dan nama unik per tahun;
  - ustadz-tahfidz hanya melihat halaqah yang ia ampu; URL view/edit halaqah lain → 403/404;
  - admin-sekolah melihat semua dalam cakupannya.
- **AC-4 Anggota:**
  - tambah → satu baris aktif;
  - pindah → baris lama ditutup (`left_on`) dan baris baru dibuat;
  - tambah ganda ke halaqah yang sama tanpa baris baru;
  - ditolak: gender/jenjang beda, santri atau halaqah nonaktif, tanggal di luar tahun ajaran, masa depan, atau sebelum keanggotaan lama;
  - `left_on = joined_on` tercatat.
- **AC-5 Validasi setoran:**
  - ditolak: ayat 0 atau > `ayah_count`; `to` sebelum `from` (dalam dan lintas surah); tanggal masa depan; halaqah nonaktif; santri bukan anggota halaqah yang dikirim pada tanggal itu (sebelum `joined_on`, pada `left_on`, anggota halaqah lain); penyimak nonaktif;
  - lintas surah maju diterima.
- **AC-6 Capaian:**
  - ziyadah 78:1–78:40 → juz 30 `proses`;
  - beberapa setoran mundur menutup 78:1–114:6 → `hafal`, `completed_on` = tanggal setoran penutup;
  - 2:141–2:142 menandai juz 1 dan 2 `proses`;
  - nilai `ulang`, murajaah, dan tasmi' tidak mengubah capaian.
- **AC-7 Hapus & idempoten:**
  - hapus setoran menghitung ulang (hafal → proses, proses → baris hilang);
  - refresh dua kali hasilnya sama; `belum` tidak disimpan.
- **AC-8 Ujian:**
  - aturan rentang per jenis; skor 0–100; `exam_on` masa depan ditolak;
  - `certificate_no` ditolak bila `ulang`, dan harus unik;
  - `lulus` → semua juz rentang `lulus_ujian` (juga tanpa setoran); `ulang` tidak mengubah;
  - hapus ujian mengembalikan status.
- **AC-9 Target:**
  - unik (tahun, jenjang, tingkat) null-aware;
  - resolusi tingkat → semua tingkat → null;
  - "Di bawah target" tampil bila juz hafal tahun ini < target, tidak tampil tanpa target;
  - jumlah query relation manager konstan untuk 1 vs 5 anggota.
- **AC-10 Akses langsung ustadz** (A ampu halaqah X; Y milik ustadz lain):
  - untuk Y, `RecordMemorization`, `DeleteMemorizationRecord`, `RecordTahfidzExam` menolak A;
  - URL/Livewire view dan hapus setoran/ujian Y → 403/404;
  - data Y tidak ada di tabel A; Select A hanya berisi X;
  - aksi Tambah/Keluarkan anggota tersembunyi dan Action-nya menolak A.
- **AC-11 Permission:**
  - `permissionsFor()` admin-sekolah dan ustadz-tahfidz persis sesuai daftar;
  - `petugas-pos` tetap hanya `view-dashboard`;
  - bendahara, musyrif, kesantrian, admin-konten tanpa menu Tahfidz.
- **AC-12 Cakupan jenjang:** user `unit_user` SLTP tidak melihat halaqah, anggota, setoran, ujian, dan target SLTA; halaqah lintas jenjang tetap terlihat.
- **AC-13 Demo:**
  - ≥2 halaqah: "Halaqah Abu Hurairah" (pengampu akun demo ustadz-tahfidz) dan satu halaqah putri;
  - anggota, setoran beberapa hari, satu ujian kenaikan juz `lulus` bersyahadah;
  - progres terisi lewat Action; konten sesuai manhaj.

## Rencana test
**Test baru (`tests/Feature/Tahfidz/`):**

| File | AC |
|---|---|
| `TahfidzModuleTest` | AC-1 |
| `QuranDataTest` | AC-2 |
| `HalaqahResourceTest` | AC-3 |
| `HalaqahMembershipTest` | AC-4 |
| `RecordMemorizationTest` | AC-5, AC-7 |
| `RefreshJuzProgressTest` | AC-6, AC-7 |
| `RecordTahfidzExamTest` | AC-8 |
| `TahfidzTargetTest` | AC-9 |
| `TahfidzAccessTest` | AC-10–12 |
| `DemoSeederTest` (sudah ada) | AC-13 |

**Test lama yang disesuaikan:**
- `Enums/ModuleTest`: 9 modul + requires;
- `InstallSeederTest`: 9 saklar, assert "tanpa baris tahfidz" dihapus, perizinan tetap;
- `SummarizeSystemStatusTest`: 8 → 9;
- `SystemOverviewWidgetTest`: "dari 9";
- `RolePermissionSeederTest`.

**Regresi:** `ModuleComplianceTest`, `ModuleSwitchVerifyTest`, `ModulesDisabledSmokeTest`.

## Urutan implementasi
**Paket 1 — modul, data, permission, visibilitas, Action**
Harus hijau: TahfidzModuleTest (enum/seeder), QuranDataTest, HalaqahMembershipTest, RecordMemorizationTest, RefreshJuzProgressTest, RecordTahfidzExamTest, TahfidzTargetTest (resolusi), TahfidzAccessTest (permission + Action), test lama.
1. `Module::Tahfidz` + lang.
2. `quran_surahs` + data + `QuranSurah`.
3. Lima tabel lain: migration, model, factory.
4. `RolePermissionSeeder` + policy, `User::staff()`, `visibleTo`, unit scope.
5. Action anggota.
6. `RefreshJuzProgress` + setoran.
7. Ujian + resolusi target.

**Paket 2 — Filament**
Harus hijau: HalaqahResourceTest, sisa TahfidzAccessTest/TahfidzModuleTest/TahfidzTargetTest, ModuleComplianceTest.
8. Empat resource + relation manager.
9. design-guide §4.

**Paket 3 — demo & dokumen**
Harus hijau: DemoSeederTest.
10. Demo tahfidz.
11. `docs/erd.md` Tahfidz:
   - buang `name_arabic` dan `target_pages_weekly`;
   - status tanpa `belum`;
   - tambah `tahfidz_exams.halaqah_id`, `recorded_by`, `grade_key`, `active_marker`;
   - `pages` manual; progres cascade.

## Risiko & pertanyaan terbuka
- **Ukuran:** satu PR (diputuskan); green-coder dipanggil per paket.
- **Data surah:** nama dan jumlah ayat dikunci AC-2 (jumlah per juz menangkap salah hitung). Nama dicek orkestrator saat review PR.
- **Netral database:** indeks global dihitung di PHP (netral SQLite/MySQL/Postgres). Refresh membaca ziyadah satu santri (ratusan baris).

## Review #1 (plan-reviewer: CHANGES_REQUESTED, 4 major, 7 minor)

| # | Tingkat | Temuan | Penanganan |
|---|---|---|---|
| 1 | major | Visibilitas hanya di Halaqah; setoran/ujian/aksi anggota bisa ditembus | `visibleTo` setoran & ujian lewat `halaqah_id` (D7); policy per record; query resource, relation manager, Select; Action cek permission + visibilitas; AC-10 |
| 2 | major | Scope/permission/relasi staff-user di Paket 2 | Paket 1 langkah 4, sebelum Action |
| 3 | major | AC-2 belum mengunci ayat per juz | Daftar 30 jumlah per juz + ayat awal ≤ `ayah_count`; data 114 surah dicantumkan, dihitung cocok |
| 4 | major | ERD belum mencerminkan penyimpangan | Langkah 11; `target_pages_weekly` dibuang; belum = tanpa baris |
| 5 | minor | D5 keliru | D5 ditulis ulang; AC-1 sisip baris `false` lalu seed ulang |
| 6 | minor | Arm ustadz-tahfidz bersama petugas-pos | Arm sendiri; AC-11 |
| 7 | minor | `halaqah_id` dikirim, tanggal masa depan, halaqah nonaktif, `left_on = joined_on` | Action + AC-4/5/8 |
| 8 | minor | Race refresh | Transaksi + `lockForUpdate`, kolom minimum |
| 9 | minor | N+1 relation manager | Eager load + memo; AC-9 query konstan |
| 10 | minor | `name_arabic`; on-delete progres | Dibuang; cascade |
| 11 | minor | Tes akses | AC-10 |

## Riwayat revisi
- 22 Okt 2026: DRAFT pertama.
- 22 Okt 2026: Revisi 1. Review #1 ditangani; Q1 tidak, Q2 setuju, satu PR. Kartu Hafalan portal wali dikeluarkan (portal wali dibangun ulang Blade+Livewire, issue terpisah). AC dinomori ulang 1–13.
