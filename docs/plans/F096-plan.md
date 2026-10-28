# F096 akademik-kkm-input-nilai — KKM mapel dan mode input nilai diniyah

Issue: #122
Status rencana: APPROVED (oleh orkestrator setelah revisi 1)
Jalur: penuh (migration). Modul: akademik. Lanjutan F027.

## Ringkasan & tujuan
Mapel diniyah mendapat KKM opsional; nilai di bawah KKM ditandai di input nilai dan rapor (cetak admin + portal wali, satu view). Halaman input nilai punya dua mode: **per rombel** (satu mapel, semua santri, "Simpan semua" + "Simpan" per baris) dan **per santri** (semua mapel satu santri + catatan wali kelas).

## Ruang lingkup
- Termasuk: kolom `passing_score`, form/tabel mapel, method penanda tunggal, mode input di halaman yang sama (`DiniyahGradeEntry`), penanda + kolom KKM di rapor, teks `lang/id`, ERD.
- Tidak termasuk: Action baru (kedua mode memakai `SaveDiniyahGrades` dengan subset data; Action hanya diubah kunci error-nya), KKM per rombel/tahun, rekap ketuntasan, notifikasi ke wali, perubahan predikat (`DiniyahPredicate` tetap), perubahan rata-rata, impor nilai.

## Model data
- Migration `2026_11_xx_add_passing_score_to_diniyah_subjects_table`: `unsignedTinyInteger('passing_score')->nullable()` (tanpa default, tanpa index). `down()` = `dropColumn`. Kompatibel SQLite/MySQL/Postgres.
- `DiniyahSubject`: tambah ke `#[Fillable]`, cast `integer`. Method tunggal sumber logika:
  `isBelowPassing(?int $score): bool` = `$score !== null && $this->passing_score !== null && $score < $this->passing_score`.
- Rentang KKM 0–100 (sama dengan rentang nilai), dijaga validasi form; tanpa CHECK di DB (sama dengan pola nilai).
- `docs/erd.md`: tambah `tinyint passing_score "KKM, nullable"` di blok `diniyah_subjects` dan satu kalimat di paragraf Rapor diniyah ("KKM opsional per mapel; nilai < KKM ditandai, nilai = KKM tuntas; tidak disimpan di nilai").

## Action class
- Tidak ada Action baru. `SaveDiniyahGrades` sudah memproses subset kunci dan memeriksa `input-diniyah-grades` + `Classroom::visibleTo`, roster, mapel aktif jenjang, nilai 0–100. **Satu perubahan:** error `invalid_score` dan `subject_unavailable` dilempar dengan kunci `scores.<student_id>.<subject_id>` (bukan `scores`), sehingga lewat `ShowsActionErrorsOnPageForm` jatuh di `data.scores.<student>.<subject>` = field baris. `student_not_in_classroom` tetap di `scores`. Perilaku simpan/atomik tidak berubah (satu error → tidak ada yang tersimpan).
- `BuildDiniyahReportCard`: setiap baris `rows` ditambah `passing_score: ?int` dan `below_passing: bool` (dari `isBelowPassing`). Mapel nonaktif yang bernilai tetap membawa KKM-nya.

## UI (Filament / Blade)
Rujukan: design-guide §4 (warna `danger` untuk penanda dihitung), §6, §7 (state kosong, notifikasi), §10 (tidak bergantung warna), §11 (cetak hitam-putih).
- **DiniyahSubjectResource**: field `passing_score` (label "KKM", helper "Kosongkan bila mapel tanpa KKM.", `integer`, `minValue(0)`, `maxValue(100)`, nullable). Kolom tabel "KKM" `numeric`, rata kanan, `tabular-nums`, placeholder "-".
- **DiniyahGradeEntry** (halaman sama). Kontrak state:
  - `data.mode`: `classroom` (default) | `student`, `ToggleButtons` inline "Per rombel" / "Per santri". Ganti mode, rombel, semester, mapel, atau santri memuat ulang state dan mengosongkan pilihan turunan.
  - Bersama: `data.classroom_id` (opsi `Classroom::visibleTo`), `data.semester` (1|2).
  - Mode rombel: `data.subject_id` (opsi = mapel aktif jenjang rombel). Baris per santri roster: nama + NIS, isian `scores.<student>.<subject>`, tombol "Simpan" baris, tautan cetak rapor. Footer: "Simpan semua". Tanpa catatan wali kelas di mode ini.
  - Mode santri: `data.student_id` (opsi = roster rombel terpilih). Isian semua mapel aktif + `notes.<student>` + tautan cetak. Footer: "Simpan nilai".
  - Method publik: `save()` (Simpan semua / Simpan nilai sesuai mode) dan `saveRow(int $studentId)` (mode rombel). Keduanya diawali `abort_unless(static::canAccess(), 403)`. `saveRow` ditolak (tanpa simpan, notifikasi danger) bila `data.mode !== 'classroom'` atau rombel/mapel belum dipilih; tidak memanggil `form->getState()`, sehingga isian tak valid di baris lain tidak menghalangi.
  - **Penyaringan server:** `scores`/`notes` dari klien tidak dipercaya. Sebelum ke Action, halaman menyaring state: mode rombel → hanya `scores[*][subject_id terpilih]`, tanpa `notes`; `saveRow` → hanya `[$studentId => [$subjectId => nilai]]`; mode santri → hanya `scores[student_id terpilih]` + `notes[student_id terpilih]`. `studentId`, `subject_id`, `student_id` tetap divalidasi Action (roster, mapel aktif jenjang, `visibleTo`).
  - **Enter tidak menyimpan semua:** di mode rombel, form tanpa submit handler (`livewireSubmitHandler` hanya di mode santri); "Simpan semua" dan "Simpan" baris adalah Action dengan `wire:click`/`->action()`, bukan submit. `mod+s` tetap memicu `save()`.
  - Penanda KKM di isian: helper "KKM :kkm" bila mapel ber-KKM; bila `isBelowPassing(nilai di state)` → hint teks "Di bawah KKM" + ikon `ExclamationTriangle`, warna `danger` (teks tetap terbaca tanpa warna). **Tanpa `live` per sel (KISS):** penanda dihitung server saat lembar dimuat dan setelah setiap simpan (state dimuat ulang dari DB).
  - Notifikasi sukses: rombel-semua "Nilai :subject rombel :classroom semester :semester disimpan.", baris "Nilai :student disimpan.", santri "Nilai :student semester :semester disimpan.". Gagal: error di field (`ShowsActionErrorsOnPageForm`).
  - State kosong: "Pilih rombel, semester, dan mapel/santri…", tanpa mapel aktif, tanpa santri aktif (pakai kunci yang ada bila cocok).
  - 360px: baris mode rombel bertumpuk (nama di atas, isian + tombol di bawah); tombol min 44px.
- **Rapor** `resources/views/akademik/diniyah-report.blade.php` (cetak admin + portal wali): kolom "KKM" (angka atau "-"); baris di bawah KKM memuat teks "Belum tuntas" di sel nilai (tebal), aman hitam-putih.
- `lang/id/pondok.php`: `pondok.diniyah.subject.passing_score|passing_score_helper`, `pondok.diniyah.entry.mode|mode_classroom|mode_student|subject|student|save_all|save_row|saved_subject|saved_student|saved_row|passing_hint|below_passing|pick_first` (diperbarui), `pondok.diniyah.report.passing_score|below_passing`.

## Permission & cakupan
Tanpa permission baru. Halaman: `input-diniyah-grades` + modul `akademik` (tetap). Resource mapel: aturan yang ada. Cakupan jenjang lewat `Classroom::visibleTo` (opsi) dan `SaveDiniyahGrades` (setiap jalur simpan, termasuk `saveRow`). Rapor: aturan cetak/portal yang ada.

## Kriteria penerimaan (bisa dites)
- **AC-1** Admin-sekolah membuat/mengubah mapel dengan KKM kosong → `passing_score` null; dengan 0, 70, 100 → tersimpan. Nilai -1, 101, `abc`, 75.5 → error di field `passing_score`, record tidak berubah.
- **AC-2** Tabel mapel menampilkan kolom KKM: angka bila terisi, "-" bila kosong.
- **AC-3** `isBelowPassing`: KKM 70 → 69 true, 70 false, 71 false, null false; KKM null → 0 false.
- **AC-4** Halaman default `data.mode = classroom`. Mode rombel: setelah rombel + semester + mapel dipilih, terlihat hanya santri aktif rombel dengan nilai tersimpan mapel itu; opsi mapel hanya mapel aktif jenjang rombel. Belum memilih mapel → state kosong, tanpa tombol simpan.
- **AC-5** Mode rombel "Simpan semua": nilai semua baris mapel terpilih tersimpan, sel kosong menghapus nilai; nilai mapel lain dan catatan wali kelas santri itu tidak berubah, termasuk bila state klien disisipi nilai mapel lain atau `notes` (disaring). Satu nilai 101 → tidak ada yang tersimpan, error di `data.scores.<student>.<subject>` baris itu.
- **AC-6** Mode rombel `saveRow(A)`: nilai A tersimpan; perubahan belum disimpan di baris B tidak masuk DB; nilai tak valid di baris B tidak menghalangi; nilai A = 101 → tidak tersimpan, error di `data.scores.<A>.<subject>`. Mengosongkan sel A lalu `saveRow(A)` menghapus nilai A saja. `saveRow` dengan id santri rombel lain, dengan `data.subject_id` mapel jenjang lain, atau saat `data.mode = student` → tidak ada baris tersimpan/berubah.
- **AC-7** Mode santri: pilih rombel + semester + santri → isian semua mapel aktif + catatan + tautan cetak santri itu; `save()` menyimpan semua mapel + catatan santri itu, santri lain di rombel tidak berubah walau state klien disisipi nilai/catatan santri lain (disaring); sel kosong menghapus. Opsi santri = roster rombel terpilih.
- **AC-8** Mode santri dengan `data.student_id` dipalsukan (santri rombel lain/jenjang lain) → tidak ada isian dimuat dan `save()` tidak menyimpan apa pun.
- **AC-9** Hak akses di kedua mode: peran tanpa `input-diniyah-grades` mendapat 403 (dataset peran yang ada tetap); wali kelas rombel lain dan admin berjenjang lain tidak bisa memuat maupun menyimpan lewat state Livewire di `save()` (dua mode) dan `saveRow()`; tidak ada baris `diniyah_grades`/`diniyah_report_notes` baru.
- **AC-10** Modul akademik mati → halaman 403 di kedua mode; nilai dan KKM tetap di DB.
- **AC-11** Penanda input (kedua mode): mapel KKM 70 dengan nilai tersimpan 65, atau nilai 65 setelah disimpan, menampilkan "Di bawah KKM"; nilai 70 tidak; mapel tanpa KKM dengan nilai 10 tidak; helper "KKM 70" tampil hanya untuk mapel ber-KKM.
- **AC-12** `BuildDiniyahReportCard` mengembalikan `passing_score` dan `below_passing` per baris, termasuk mapel nonaktif yang bernilai; rata-rata dan predikat tidak berubah.
- **AC-13** Rapor cetak admin dan portal wali: kolom KKM tampil (angka/"-"); baris 65 vs KKM 70 memuat "Belum tuntas"; baris 70 vs KKM 70 dan baris tanpa KKM tidak memuatnya.

## Rencana test
| AC | File | Test usulan |
|---|---|---|
| 1, 2 | `tests/Feature/Akademik/DiniyahSubjectResourceTest.php` | `AC-1: saves a subject with or without a passing score`, `AC-1: rejects a passing score outside 0-100 or not an integer`, `AC-2: lists the passing score or a dash` |
| 3, 12 | `tests/Feature/Akademik/BuildDiniyahReportCardTest.php` | `AC-3: marks only scores strictly below the passing score`, `AC-12: returns the passing score and the flag per row` |
| 4–8, 10, 11 | `tests/Feature/Akademik/DiniyahGradeEntryPageTest.php` (kontrak di header diperbarui) | `AC-4: loads one subject for the classroom roster`, `AC-5: save all…`, `AC-6: saves one row only…`, `AC-7: per-student mode saves every subject and the note`, `AC-8: ignores a forged student`, `AC-10: closes both modes with the module`, `AC-11: flags a score below the passing score` |
| 9 | `tests/Feature/Akademik/DiniyahReportAdversarialTest.php` | `AC-9: blocks another homeroom teacher in both modes and on saveRow` (+ dataset 403 di file page) |
| 13 | `tests/Feature/Akademik/PrintDiniyahReportTest.php`, `tests/Feature/PortalWali/DiniyahReportTest.php` | `AC-13: prints the passing score and flags an incomplete subject` |

Error kunci `scores.<student>.<subject>` (AC-5/6): tambahkan satu assert di `SaveDiniyahGradesTest.php` (`AC-5: reports an invalid score on its own cell key`); test lama yang hanya mengecek `ValidationException` tetap berlaku, yang mengecek kunci `scores` disesuaikan.

Catatan red-tester: test lama halaman memakai kontrak satu lembar semua mapel (`AC-3`, `AC-5`, `AC-6`, `AC-9`, `AC-1` di DiniyahGradeEntryPageTest; test livewire state di Adversarial). Sesuaikan ke kontrak baru pada fase RED; jangan hapus cakupan akses/validasi yang ada.

## Urutan implementasi
**Paket 1 — data + rapor + kunci error** (hijau: SaveDiniyahGradesTest, DiniyahSubjectResourceTest, BuildDiniyahReportCardTest, PrintDiniyahReportTest, PortalWali/DiniyahReportTest)
1. Migration + model (fillable, cast, `isBelowPassing`). 2. Form + kolom tabel mapel + lang. 3. `BuildDiniyahReportCard` field baru. 4. View rapor kolom KKM + "Belum tuntas" + lang. 5. ERD. 6. Kunci error per sel di `SaveDiniyahGrades`.

**Paket 2 — halaman input** (hijau: DiniyahGradeEntryPageTest, DiniyahReportAdversarialTest, ModuleComplianceTest)
7. `data.mode` + pemuatan state per mode. 8. Mode rombel: pilih mapel, baris, `save()` tersaring, `saveRow()` dengan guard, tanpa submit Enter. 9. Mode santri: pilih santri, isian + catatan, `save()` tersaring. 10. Penanda KKM + helper (tanpa live). 11. Lang, Pint, cek 360/1024/desktop + dark mode (design-guide §0).

## Risiko & pertanyaan terbuka
1. Diputuskan orkestrator: mode rombel = satu mapel; catatan wali kelas hanya di mode santri.
2. Diputuskan orkestrator: KKM 0 diizinkan (tidak ada nilai yang ditandai).
3. Penanda tidak berubah selama mengetik (baru setelah simpan); diterima demi KISS.

## Riwayat revisi
- DRAFT 1 (28 Okt 2026).
- Revisi 1 (28 Okt 2026): kunci error per sel di Action, guard `saveRow`, penyaringan state server, Enter tanpa submit di mode rombel, penanda tanpa live per sel; AC-5/6/7/11 dan paket kerja diperbarui. Status APPROVED oleh orkestrator.

## Review #1
CHANGES_REQUESTED (0 blocker, 1 major, 4 minor).

| # | Tingkat | Temuan | Penanganan |
|---|---|---|---|
| 1 | major | AC-5/6 minta error di field baris, padahal `SaveDiniyahGrades::fail()` selalu memakai kunci `scores` | Opsi (b): Action melempar `invalid_score`/`subject_unavailable` di `scores.<student>.<subject>`; klaim "Action tidak diubah" dihapus; test kunci di `SaveDiniyahGradesTest`, test lama disesuaikan bila mengecek kunci `scores` |
| 2 | minor | `save`/`saveRow` publik perlu guard eksplisit; input klien tidak dipercaya | `abort_unless(canAccess)` di baris pertama, `saveRow` ditolak bila mode bukan `classroom`; AC-6 menambah santri rombel lain, mapel jenjang lain, mode salah |
| 3 | minor | Penyaringan state `scores` belum tegas | Bagian UI "Penyaringan server"; AC-5/AC-7 menambah kasus state disisipi |
| 4 | minor | Enter di mode rombel bisa memicu Simpan semua | Mode rombel tanpa submit handler; tombol memakai Action `wire:click` |
| 5 | minor | `live(onBlur)` per sel | Dihapus; penanda dari nilai tersimpan saat dimuat dan setelah simpan; AC-11 disesuaikan |
