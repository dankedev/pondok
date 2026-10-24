# F026 kedisiplinan — Pelanggaran & poin kedisiplinan

Issue: #7
Jalur: penuh (migration, permission, Action yang mengubah data)
Status rencana: APPROVED

## Ringkasan & tujuan
Kesantrian dan musyrif mencatat pelanggaran santri dari katalog jenis pelanggaran berpoin (kategori ringan/sedang/berat). Poin dijumlahkan per santri per tahun ajaran dan diberi label ambang (mis. "Peringatan 1") di rekap agar pembina tahu siapa yang perlu ditindaklanjuti. Wali melihat total dan daftar pelanggaran anaknya di portal. Rujukan: ERD "Kedisiplinan", PRD §3.13. Pola acuan: F031 Kesehatan (catatan per santri, cakupan musyrif), F025 (modul, rekap, portal), F024 (`Student::visibleStudentsFor`).

Keputusan pemilik (24 Okt 2026): K1 katalog berpoin + kategori, ±8 jenis bawaan; K2 tanpa poin positif; K3 tabel ambang yang bisa diatur, hanya label di rekap; K4 kesantrian kelola, musyrif catat santri binaan, admin-sekolah lihat, wali-kelas tidak ikut; K5 portal wali total + daftar tanpa deskripsi; K6 per tahun ajaran; K7 requires Akademik saja; K8 tanpa notifikasi; K9 koreksi lewat pembatalan dengan alasan.

## Ruang lingkup
- Termasuk: modul `kedisiplinan`; tabel `violation_types`, `student_violations`, `discipline_levels`; Action simpan jenis, simpan/hapus ambang, catat/ubah pelanggaran, batalkan pelanggaran; activity log `kedisiplinan`; resource jenis, ambang, dan pelanggaran; halaman rekap poin; bagian portal wali; permission; katalog + ambang bawaan saat instalasi; data demo; `lang/id`; ERD, PRD, design-guide §3 (ikon navigasi).
- Tidak termasuk (eksplisit): poin positif/prestasi pengurang; sanksi, surat peringatan, atau dokumen cetak; notifikasi WhatsApp; unggah bukti; banding; widget dashboard; ekspor; integrasi otomatis dari Absensi (alpa) atau Perizinan (terlambat kembali); akses wali-kelas; hapus fisik catatan pelanggaran.

## Keputusan desain
- **D1 Poin snapshot**: `student_violations.points` disalin dari jenis saat dicatat; mengubah poin di katalog tidak mengubah catatan lama. Mengganti jenis pada catatan menyalin ulang poin jenis baru.
- **D2 Tahun ajaran diturunkan** dari `occurred_on` setiap simpan: `academic_years` dengan `starts_on` ≤ tanggal ≤ `ends_on`; bila lebih dari satu cocok, pilih `starts_on` terbaru; tidak ada yang cocok → `ValidationException` pada `occurred_on`. Tanggal tidak boleh di masa depan.
- **D3 Tidak dihapus**: catatan keliru dibatalkan (`cancelled_at`, `cancelled_by`, `cancel_reason` wajib); catatan batal tetap tampil (badge "Dibatalkan"), tidak dihitung, tidak bisa diubah atau dibatalkan ulang. Salah santri: batalkan lalu catat ulang (ubah tidak mengganti santri, pola Kesehatan).
- **D4 Jenis pelanggaran tidak dihapus**, cukup `is_active = false`; jenis nonaktif tidak bisa dipilih untuk catatan baru/ganti jenis.
- **D5 Cakupan** satu sumber `Student::visibleStudentsFor($user)`: pemegang `view-any:student` (super-admin, kesantrian, admin-sekolah) semua santri dalam `unit_user`; musyrif hanya santri dengan penempatan kamar aktif yang ia awasi. Global scope `visible` pada `StudentViolation` lewat `student_id` (pola `HealthVisit`).
- **D6 Deskripsi** opsional, ≤1.000 karakter, tidak dienkripsi, tidak pernah tampil di portal wali.
- **D7 Ambang hanya label**: level santri = baris `discipline_levels` dengan `min_points` terbesar ≤ total poin; dihitung saat rekap, tidak disimpan, tanpa efek lain. Ambang boleh dihapus (tidak dirujuk tabel lain) lewat Action `DeleteDisciplineLevel`.
- **D8 Navigasi** grup baru `pondok.navigation.kedisiplinan`.
- **D9 Portal wali melewati scope `visible`**: wali tidak punya `view-any:student` dan bukan musyrif, sehingga scope `visible` menyembunyikan semua catatan. Pola F025: `User::portalViolations(Student $student)` memakai `StudentViolation::withoutGlobalScope('visible')`, `where student_id`, `countable()`, tahun ajaran aktif, dengan relasi jenis; `$student` wajib dari `portalStudentsWithSummary()->findOrFail` di `ShowStudent`. Dipanggil hanya bila `Module::Kedisiplinan->isEnabled()`.
- **D10 Activity log `kedisiplinan`** ditulis manual (`activity()->inLog('kedisiplinan')`, pola `GuardsHealthVisit::log`) di Action catat (`created`), ubah (`updated`, daftar nama kolom yang berubah), dan batal (`cancelled`, beserta alasan). Properti: `student_id`, `violation_type_id`, `occurred_on`, `points`; deskripsi tidak masuk log. Cukup method privat per Action, tanpa trait baru.
- **D11 Tanpa tahun ajaran aktif**: rekap (bawaan filter) dan portal menampilkan state kosong, bukan galat.

## Model data
- `violation_types`: `id`, `name` string(100) unique, `category` string(10) cast enum `ViolationCategory` (`ringan`/`sedang`/`berat`, label + warna badge di `lang/id`), `points` unsignedSmallInteger (1–1000), `is_active` bool default true, timestamps.
- `student_violations`: `id`, `student_id` FK `cascadeOnDelete` (hanya force delete santri), `violation_type_id` FK `restrictOnDelete`, `academic_year_id` FK `restrictOnDelete`, `occurred_on` date, `points` unsignedSmallInteger, `description` text nullable, `recorded_by` FK users nullable `nullOnDelete`, `cancelled_at` datetime nullable, `cancelled_by` FK users nullable `nullOnDelete`, `cancel_reason` string(255) nullable, timestamps. Index (`student_id`, `academic_year_id`), (`occurred_on`).
- `discipline_levels`: `id`, `name` string(50), `min_points` unsignedSmallInteger unique (≥1), timestamps.
- Tanpa soft delete. Model + factory untuk ketiganya. `Student::violations()`, scope `StudentViolation::countable()` (belum batal).

## Action class (`app/Actions/Kedisiplinan/`)
- `SaveViolationType::handle(User $actor, array $data, ?ViolationType $type = null)`: authorize create/update; nama unik, kategori valid, poin 1–1000.
- `SaveDisciplineLevel::handle(User $actor, array $data, ?DisciplineLevel $level = null)`: nama wajib, `min_points` ≥1 dan unik.
- `DeleteDisciplineLevel::handle(User $actor, DisciplineLevel $level)`: authorize `delete`, hapus baris (preseden `DeleteHealthVisit`); dipanggil dari aksi hapus resource.
- `RecordStudentViolation::handle(User $actor, array $data, ?StudentViolation $violation = null)`: authorize create/update; catatan baru hanya untuk santri `aktif` di `visibleStudentsFor($actor)` (di luar cakupan → `ValidationException` pada `student_id`, pola Kesehatan); jenis aktif; D1, D2; `recorded_by` = actor saat buat; ubah menolak catatan batal dan tidak mengganti `student_id`/`recorded_by`. Galat lewat `ValidationException` dengan kunci field. Log D10.
- `CancelStudentViolation::handle(User $actor, StudentViolation $violation, string $reason)`: authorize `cancel`; alasan wajib (≤255); sudah batal → galat. Log D10.
- Rekap: `SummarizeDisciplinePoints::handle(User $actor, int $academicYearId, ?int $unitId)` → santri dalam cakupan dengan `COUNT`, `SUM(points)` catatan `countable`, urut poin terbanyak, plus label level (D7). Query portabel tanpa fungsi khusus database.

## UI (Filament / Blade)
- `ViolationTypeResource` (list kategori badge, poin, aktif; filter kategori/aktif; create/edit). `DisciplineLevelResource` (list urut `min_points`; create/edit/delete), keduanya di grup Kedisiplinan.
- `StudentViolationResource`: list (tanggal, santri + NIS, jenjang, jenis, kategori badge, poin, pencatat, badge Dibatalkan); filter tahun ajaran (bawaan aktif), jenjang, kategori, jenis, status batal; cari nama/NIS; create/edit (pilih santri dari `visibleStudentsFor`, jenis aktif saja); aksi Batalkan (alasan wajib, modal konfirmasi design-guide §8). View menampilkan alasan + pembatal.
- Page `DisciplineRecap`: filter tahun ajaran (bawaan aktif) + jenjang; kolom santri, NIS, jenjang, jumlah pelanggaran, total poin, level; hanya santri dengan total > 0; state kosong §7.
- Portal wali (`ShowStudent`, D9): bagian "Kedisiplinan" bila `Module::Kedisiplinan` aktif — total poin tahun ajaran aktif + daftar catatan belum batal (tanggal, jenis, kategori, poin) dari `portalViolations`, tanpa deskripsi/pencatat; pesan kosong "Tidak ada catatan pelanggaran" (juga bila tidak ada tahun ajaran aktif).
- Semua teks di `lang/id/pondok.php` (kunci `kedisiplinan.*`). Format tanggal/angka mengikuti design-guide §5. Cek 360px, 1024px, desktop.

## Permission & cakupan
- `RESOURCE_MODELS` + `ViolationType`, `DisciplineLevel`, `StudentViolation`. `LIMITED_RESOURCE_ACTIONS`: `ViolationType` = viewAny, view, create, update; `StudentViolation` = viewAny, view, create, update, cancel; `DisciplineLevel` = aksi resource standar.
- super-admin: semua. kesantrian: semua permission ketiga model. musyrif: `StudentViolation` viewAny, view, create. admin-sekolah: `StudentViolation` viewAny, view + `DisciplineRecap`. Peran lain (termasuk wali-kelas, bendahara, petugas-pos): tanpa akses.
- Rekap boleh dibuka pemegang `view-any:student-violation`, datanya disaring `visibleStudentsFor`. Musyrif boleh membuka rekap, tetapi isinya hanya santri binaannya.
- Modul: `Module::Kedisiplinan = 'kedisiplinan'`, `requires()` = `[Akademik]`; resource `BelongsToModule`, page `getModule()`; saklar di `SettingSeeder`, dinyalakan oleh `pondok:upgrade` di instance lama (pola F025). Catatan: cakupan musyrif tetap membaca `room_assignments` meski modul Asrama mati.

## Kriteria penerimaan
- **AC-1 Modul**: `Module::Kedisiplinan->requires()` = `[Akademik]`. Saklar mati (sebagai super-admin): (a) URL index ketiga resource dan rekap → 403; (b) navigasi panel tidak memuat tautan Kedisiplinan; (c) portal wali tidak menampilkan bagian Kedisiplinan dan halaman tetap 200; (d) jumlah baris data tetap. `ModuleComplianceTest` lulus.
- **AC-2 Peran**: kesantrian bisa semua; musyrif lihat + catat pelanggaran dan membuka rekap (ubah, batal, jenis, ambang → 403); admin-sekolah lihat pelanggaran + rekap (create/edit/jenis/ambang → 403). Wali-kelas, bendahara, admin-konten: route ketiga resource dan rekap → 403, dan navigasi panelnya tanpa tautan Kedisiplinan.
- **AC-3 Catat**: kesantrian mencatat pelanggaran santri aktif → satu baris dengan `points` = poin jenis, `academic_year_id` = tahun ajaran yang memuat `occurred_on` (dua tahun ajaran tumpang tindih → yang `starts_on` terbaru), `recorded_by` = actor, `cancelled_at` null; satu activity log `kedisiplinan` event `created` tanpa teks deskripsi. Ubah dan batal masing-masing menulis log `updated`/`cancelled`.
- **AC-4 Snapshot**: poin jenis diubah → `points` catatan lama tetap; ubah catatan ke jenis lain → `points` = poin jenis baru; ubah tanggal ke tahun ajaran lain → `academic_year_id` ikut berubah; `student_id` dan `recorded_by` tidak berubah walau dikirim.
- **AC-5 Validasi catat**: tanggal besok, tanggal di luar semua tahun ajaran (galat pada `occurred_on`), jenis nonaktif, santri berstatus bukan `aktif`, deskripsi >1.000 karakter → `ValidationException`, jumlah baris tidak berubah.
- **AC-6 Batal**: batal dengan alasan → `cancelled_at`, `cancelled_by`, `cancel_reason` terisi dan catatan tidak dihitung di total; alasan kosong → galat; batal ulang atau ubah catatan batal → galat tanpa perubahan.
- **AC-7 Musyrif**: musyrif melihat dan mencatat hanya santri dengan kamar aktif di asrama/kamar yang ia awasi; mencatat santri lain → `ValidationException` pada `student_id` tanpa baris baru; list tidak memuat catatan santri lain; santri pindah kamar → catatan lama tak terlihat oleh musyrif lama.
- **AC-8 Cakupan jenjang**: kesantrian ber-`unit_user` SLTP tidak melihat catatan santri SLTA di list/rekap dan tidak bisa mencatat untuknya.
- **AC-9 Katalog jenis**: simpan jenis dengan nama duplikat, kategori di luar enum, atau poin 0/>1000 → galat; menonaktifkan jenis tidak mengubah catatan lama dan jenis itu tidak lagi muncul di pilihan form.
- **AC-10 Ambang & rekap**: total poin per santri untuk tahun ajaran terpilih benar (catatan batal dan tahun ajaran lain tidak dihitung), urut terbanyak, santri tanpa poin tidak tampil; dengan ambang 25 "Peringatan 1" dan 50 "Peringatan 2", total 30 → "Peringatan 1", 50 → "Peringatan 2", 10 → tanpa label; `min_points` duplikat atau 0 → galat; `DeleteDisciplineLevel` menghapus ambang (musyrif/admin-sekolah → `AuthorizationException`); tanpa tahun ajaran aktif, rekap render 200 dengan state kosong. Musyrif membuka rekap → hanya santri binaannya.
- **AC-11 Portal wali**: wali (peran `wali`, tanpa `view-any:student`, tanpa penugasan musyrif) melihat total poin tahun ajaran aktif dan daftar (tanggal, jenis, kategori, poin) anaknya; catatan tahun ajaran lain, catatan batal, dan teks deskripsi tidak muncul di HTML; catatan anak lain tidak tampil dan rincian anak lain → 404; tanpa tahun ajaran aktif → halaman 200 dengan pesan kosong.
- **AC-12 Instalasi & demo**: `InstallSeeder` membuat 8 jenis bawaan (3 kategori) dan 3 ambang bawaan; seed ulang tidak menggandakan dan tidak menimpa poin yang sudah diubah; `DemoSeeder` membuat beberapa catatan lewat Action (termasuk satu yang dibatalkan), seed ulang tidak menggandakan.

## Rencana test
- `tests/Feature/Kedisiplinan/KedisiplinanModuleTest.php` — AC-1, AC-2 (`it hides kedisiplinan when module disabled`, `it limits access per role`).
- `tests/Feature/Kedisiplinan/RecordStudentViolationTest.php` — AC-3, AC-4, AC-5, AC-7, AC-8.
- `tests/Feature/Kedisiplinan/CancelStudentViolationTest.php` — AC-6.
- `tests/Feature/Kedisiplinan/ViolationTypeTest.php` — AC-9 (Action + Livewire form).
- `tests/Feature/Kedisiplinan/DisciplineRecapTest.php` — AC-10 (Action + page render dengan cakupan).
- `tests/Feature/PortalWaliDisciplineTest.php` — AC-11.
- `tests/Feature/Kedisiplinan/KedisiplinanSeederTest.php` — AC-12.
- `tests/Feature/ModuleComplianceTest.php` — dijalankan ulang tanpa perubahan kecuali bila perlu daftar inti.

## Urutan implementasi
**Paket 1 — data + Action** (hijau: RecordStudentViolationTest, CancelStudentViolationTest, ViolationTypeTest bagian Action, DisciplineRecapTest bagian Action)
1. Enum `Module::Kedisiplinan` + `requires()`, saklar `SettingSeeder`, `pondok:upgrade`.
2. Migration 3 tabel, enum `ViolationCategory`, model + factory, relasi, scope `visible`/`countable`.
3. Permission + peran di `RolePermissionSeeder`, policy.
4. Action `SaveViolationType`, `SaveDisciplineLevel`, `DeleteDisciplineLevel`, `RecordStudentViolation`, `CancelStudentViolation`, `SummarizeDisciplinePoints` (+ activity log D10).

**Paket 2 — Filament** (hijau: KedisiplinanModuleTest, ViolationTypeTest, DisciplineRecapTest, ModuleComplianceTest)
5. `lang/id` + grup navigasi + ikon di design-guide §3.
6. Resource jenis, ambang, pelanggaran (aksi Batalkan); page `DisciplineRecap`.

**Paket 3 — portal + seeder** (hijau: PortalWaliDisciplineTest, KedisiplinanSeederTest)
7. `User::portalViolations` + bagian Kedisiplinan di portal wali (D9).
8. `DisciplineSeeder` (8 jenis + 3 ambang) di `InstallSeeder`; data demo lewat Action.

## Risiko & pertanyaan terbuka
- Cakupan musyrif bergantung pada `room_assignments`; bila modul Asrama mati, musyrif praktis tidak melihat santri. Diterima (musyrif hanya bermakna dengan Asrama).
- Jenis bawaan (usulan, bisa diubah lembaga): ringan — terlambat shalat berjamaah (5), tidak mengikuti kajian tanpa izin (5), berpakaian tidak sesuai aturan (5); sedang — terlambat kembali dari izin (15), membawa telepon genggam tanpa izin (20), keluar pondok tanpa izin (25); berat — berkelahi (50), mencuri (75). Ambang bawaan: Peringatan 1 (25), Peringatan 2 (50), Peringatan 3 (75).
- Tidak ada pertanyaan terbuka; semua keputusan K1–K9 sudah dijawab pemilik.

## Riwayat revisi
- 24 Okt 2026: DRAF ruang lingkup dengan keputusan K1–K9.
- 24 Okt 2026: Final setelah pemilik menyetujui semua rekomendasi K1–K9; status DRAFT untuk review plan-reviewer.
- 24 Okt 2026: Revisi Review #1 (lihat di bawah); status APPROVED atas keputusan orkestrator.

## Review #1 — 2026-10-24
Verdict plan-reviewer: CHANGES_REQUESTED (satu putaran revisi).
1. [major] Portal wali tersembunyi oleh scope `visible` → D9: `User::portalViolations` dengan `withoutGlobalScope('visible')`, santri dari `portalStudentsWithSummary()->findOrFail`, hanya catatan belum batal tahun ajaran aktif, hanya bila modul aktif; AC-11 menambah kasus wali tanpa `view-any:student` dan anak lain.
2. [minor] Activity log → D10, log `kedisiplinan` di Action catat/ubah/batal tanpa trait baru; diassert di AC-3.
3. [minor] Tahun ajaran ganda/tidak ada → D2 (`starts_on` terbaru, galat `occurred_on`), D11 state kosong; masuk AC-3, AC-5, AC-10, AC-11.
4. [minor] Hapus ambang → Action `DeleteDisciplineLevel` (preseden `DeleteHealthVisit`); masuk AC-10.
5. [minor] Santri di luar cakupan → `ValidationException` pada `student_id`; ditetapkan di AC-7.
6. [minor] AC-1/AC-2 memisahkan assert route 403 dan navigasi tanpa tautan; musyrif boleh membuka rekap dengan isi santri binaan saja (AC-2, AC-10, bagian Permission).
