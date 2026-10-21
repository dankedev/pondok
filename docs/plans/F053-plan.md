# F053 psb-uji-konkurensi — Uji konversi PSB bersamaan di MySQL (audit Grup 3)

Issue: #20
Status rencana: APPROVED (revisi 1 menjawab Review #1; tanpa putaran ketiga)
Jalur: penuh (PSB, konversi pendaftar → santri)

## Ringkasan & tujuan
Audit ISO Grup 3 (`docs/audits/iso-25010-23026-audit.md`, butir 9): `ReRegisterApplicant` memakai `lockForUpdate`, unique
`students.applicant_id`, `students.nis`, `cohorts(unit_id, entry_year)` dan loop percobaan ulang, tetapi baru diuji di
SQLite (yang mengunci seluruh database, sehingga perilaku kunci baris InnoDB belum pernah dibuktikan). F053 menambah
uji konkurensi nyata di MySQL: dua proses PHP terpisah menjalankan konversi pada saat yang sama. Bila ditemukan bug,
diperbaiki di Action dengan test regresi.

## Ruang lingkup
- Termasuk: satu file test Pest yang hanya berjalan bila env `F053_MYSQL_DATABASE` diisi (selain itu `skip`), satu skrip
  PHP pemicu proses anak di `tests/Fixtures`, perbaikan Action bila test menemukan bug.
- Tidak termasuk:
  - Konsolidasi validasi ke FormRequest/DTO (YAGNI, sesuai issue).
  - Menjalankan test MySQL di CI (`tests.yml` tetap SQLite; layanan MySQL di CI = biaya baru tanpa pemakai lain).
  - MariaDB: semantik kunci InnoDB sama; tidak diuji terpisah (dicatat di evidence).
  - Mengubah migration/skema, kecuali test membuktikan bug yang butuh unique baru.

## Desain test
File: `tests/Feature/Actions/Psb/ReRegisterConcurrencyMysqlTest.php`.

Koneksi: test membuat koneksi `f053_mysql` dari env `F053_MYSQL_HOST` (bawaan 127.0.0.1), `_PORT` (3306),
`_DATABASE`, `_USERNAME`, `_PASSWORD`, menjadikannya default, lalu `migrate:fresh` di koneksi itu.
Pengaman: nama database wajib berakhiran `_test`; selain itu test gagal sebelum `migrate:fresh` (mencegah menghapus
database dev). Kredensial hanya dari env, tidak pernah ditulis di repo.

Data: dibuat di proses test dengan helper F012 yang sudah ada (`daftarUlangFixture`, `daftarUlangApplicant`,
`daftarUlangValidDocument`, factory `FeeOverride`), ter-commit langsung (koneksi MySQL tidak dibungkus transaksi
RefreshDatabase). Disk `private` diarahkan ke direktori sementara yang sama untuk proses induk dan anak.

Proses anak: `tests/Fixtures/f053-reregister.php <applicant_id> <user_id> <start_at> <hold_ms>` mem-boot aplikasi
(env koneksi diteruskan lewat `Process::env`), menunggu sampai `start_at` (penghalang waktu, agar keduanya mulai
bersamaan), lalu memanggil `ReRegisterApplicant::handle`. `hold_ms` > 0 memasang dekorator `CopyApplicantDocuments`
yang tidur sebelum menyalin, sehingga transaksi pemenang tetap terbuka saat proses lain masuk (memaksa tumpang tindih
nyata, bukan kebetulan waktu). Keluaran satu baris JSON: `ok`, `student_id`, `nis`, atau `error` (kelas + pesan).
Dua anak dijalankan dengan `Process::start` lalu `wait`.

## Kriteria penerimaan
- AC-1: Tanpa env `F053_MYSQL_DATABASE`, test ditandai skip dan tidak menyentuh koneksi apa pun; suite SQLite/CI tidak berubah.
- AC-2: Nama database tanpa akhiran `_test` → test gagal dengan pesan jelas sebelum migrasi apa pun.
- AC-3 (pendaftar sama, ditahan): dua proses mengonversi pendaftar yang sama → tepat satu `ok`, yang lain
  `ValidationException` `already_converted`; tidak ada error lain (deadlock, lock wait timeout, 500).
- AC-4 (data AC-3): tepat satu `students` untuk `applicant_id`; semua tagihan pendaftar ber-`student_id` santri itu;
  keringanan pendaftar dipindah sekali (jumlah baris tetap); dokumen hasil salinan = jumlah berkas `valid`, masing-masing
  `source_applicant_document_id` unik; satu kaitan `student_guardian`; satu activity `psb/converted` dan satu
  `keuangan/applicant_billing_transferred`; file di disk = jumlah dokumen (salinan pihak yang kalah tidak tertinggal).
- AC-5 (pendaftar sama, tanpa tahan, uji asap 3 putaran): setiap putaran memenuhi AC-3/AC-4.
- AC-6 (dua pendaftar berbeda, jenjang & tahun masuk sama, angkatan belum ada, ditahan): kedua proses `ok`; NIS berbeda
  dan berurutan; tepat satu `cohorts` (unit, tahun); masing-masing santri punya tagihan/dokumennya sendiri.
- AC-7: hasil run MySQL (versi server, jumlah test lulus) dicatat di `evidence` F053.
- AC-8: bila AC-3..AC-6 menemukan bug, perbaikan di `ReRegisterApplicant` (atau Action yang dipanggilnya) disertai test
  regresi SQLite di `ReRegisterApplicantTest.php` yang gagal sebelum perbaikan; perilaku test F012 lain tidak berubah.

## Risiko
- Deadlock InnoDB (SQLSTATE 40001/1213) bukan `UniqueConstraintViolationException`, jadi tidak dicoba ulang oleh loop
  saat ini. Bila muncul di AC-5/AC-6 → bug, diperbaiki (dicoba ulang seperti unique violation) per AC-8.
- Waktu: proses anak berjalan di waktu nyata, bukan `travelTo`. Tanggal masuk dihitung dari waktu nyata; asersi tidak
  bergantung tanggal.
- 8GB RAM: dua proses PHP singkat, tanpa server tambahan; MySQL yang dipakai adalah layanan native yang sudah berjalan.

## Review #1 — 2026-10-21

Verdict: CHANGES_REQUESTED

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | AC-6 | Penahanan (`hold`) ada setelah `createStudent`. Dengan "angkatan belum ada", proses kedua terblokir di insert `cohorts` dan baru bentrok di cohort; balapan NIS otomatis (`GenerateStudentNumber` membaca tanpa kunci, dua proses menghitung NIS sama, unique `students.nis` + retry) justru kasus produksi paling umum (angkatan sudah ada) dan tidak terbukti. | AC-6 dua kasus: angkatan belum ada, dan angkatan sudah ada (NIS bentrok lalu retry, NIS berbeda dan berurutan). |
| 2 | major | AC-6 / ERD guardians | `ResolveApplicantGuardian` select lalu insert; `guardians` tanpa unique (hanya `user_id`). Dua kakak-adik (wali sama) dikonversi bersamaan menghasilkan dua baris wali ganda, padahal tujuan fitur adalah "tanpa data ganda". Rencana diam soal ini. | Tambah kasus wali sama di AC-6 dan putuskan: tolok ukur diterima (duplikat wali tidak merusak, dicatat sebagai catatan, bukan bug) atau perbaikan. Jangan buat unique baru tanpa alasan; YAGNI. |
| 3 | major | Desain test, waktu | Helper `daftarUlangApplicant` memakai `travelTo(2026-10-15)` di proses induk; anak berjalan di waktu nyata. Tanggal gelombang, jatuh tempo, dan `earliestEnteredOn` bergeser seiring waktu sehingga test bisa gagal/hijau karena tanggal, bukan konkurensi. "Asersi tidak bergantung tanggal" tidak menjamin data yang disiapkan di waktu palsu valid di waktu nyata. | Teruskan instan waktu induk ke anak (argumen skrip, `Carbon::setTestNow` di skrip) agar sama dengan fixture. |
| 4 | major | AC-3 | Bukti tumpang tindih tidak ditegaskan. Pemenang/kalah acak dan boot proses bervariasi; bila berjalan berurutan, test tetap hijau tanpa menguji kunci baris (hijau semu). | Ukur durasi proses yang kalah (>= sebagian `hold_ms`) atau catat waktu mulai/selesai di JSON dan assert tumpang tindih. |
| 5 | major | Desain test, koneksi/RefreshDatabase | `RefreshDatabase` global (`tests/Pest.php` baris 103) berjalan di SQLite `:memory:` sebelum badan test; mengganti default connection di tengah test tanpa memulihkannya merusak test lain di proses worker sama. Data MySQL ter-commit dan tidak terbungkus transaksi, sehingga antar-test/putaran AC-5 perlu dibersihkan. Rencana tidak menyebut pemulihan default, `DB::purge`, atau pembersihan. | Wajib: `afterEach` kembalikan default connection + purge + hapus direktori sementara; `migrate:fresh` di `beforeEach` (bukan sekali) atau satu seed per putaran; test harus dibatasi satu worker (tidak ada pemakaian paralel dengan DB yang sama). |
| 6 | minor | Proses anak | Env induk (phpunit.xml: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL=""`) diwarisi anak; Dotenv tidak menimpa env yang sudah ada. Anak harus eksplisit mengganti semua `DB_*`, `APP_ENV`, dan root disk `private` (rencana hanya menyebut "direktori sementara yang sama", tidak bagaimana anak mengetahuinya). Koneksi `f053_mysql` di induk tidak sama dengan koneksi bawaan di anak. | Satu cara saja: induk dan anak sama-sama memakai koneksi `mysql` dari env; path disk lewat argumen/env. |
| 7 | minor | AC-1, AC-2, AC-7 | AC-1 (skip) dan AC-7 (evidence) tidak bisa di-assert di Pest; AC-2 butuh guard yang bisa dipanggil terpisah. | Jadikan guard fungsi kecil di file test dengan satu assert untuk nama non-`_test`; tandai AC-1/AC-7 sebagai kriteria non-test. |
| 8 | minor | AC-4 | "File salinan pihak yang kalah tidak tertinggal" hampir vacuous: pihak kalah ditolak di kunci sebelum menyalin, jadi `removeCopiedFiles` tidak terpicu. | Tetap boleh sebagai asersi jumlah file, tapi jangan klaim kompensasi terbukti. |
| 9 | minor | AC-5 | Tanpa `hold`, 5 putaran probabilistik dan bukti tidak deterministik. | Wajar; cukup catat di evidence bahwa ini uji asap, bukan bukti. Pertimbangkan 3 putaran. |

Pemeriksaan lain yang bersih: scope sesuai issue, tanpa Redis/CI baru, uji skip saat env kosong, pengaman `_test` ada, tidak menyentuh skema, kredensial hanya dari env.

## Revisi 1 (menjawab Review #1)
- Major 1: AC-6b baru, angkatan (unit, 2027) sudah ada sebelum balapan. Proses kedua menghitung NIS yang sama dari
  snapshot REPEATABLE READ, tertahan di unique `students.nis` sampai pemenang commit, lalu dicoba ulang → NIS berbeda.
- Major 2: wali ganda kakak-adik yang dikonversi pada detik yang sama **diterima sebagai catatan**, tidak diperbaiki di
  F053 (butuh kunci/unique pada nomor HP wali yang dinormalisasi = perubahan skema/aturan bisnis di luar issue).
  AC-6 memakai wali berbeda. Dilaporkan ke user sebagai keputusan.
- Major 3: induk meneruskan `now()` (instan `travelTo`) ke anak sebagai argumen; anak memanggil `Date::setTestNow`.
- Major 4: anak melaporkan `elapsed_ms` sejak `start_at`; AC-3 meng-assert proses yang kalah selesai ≥ 80% `hold_ms`
  (bukti ia menunggu kunci pemenang, bukan berjalan berurutan).
- Major 5: `migrate:fresh` di setiap test (beforeEach) sudah memisahkan antar-test; putaran AC-5 memakai pendaftar
  berbeda. `afterEach` mem-purge koneksi `f053_mysql`; aplikasi dibuat ulang per test sehingga default connection pulih.
  Direktori disk dipakai dari `Storage::fake('private')` fixture (dibersihkan fixture berikutnya). Dijalankan sebagai satu
  file tanpa `--parallel`; di suite paralel test ini skip.
- Minor: anak menerima `DB_*`, `APP_ENV=testing`, `APP_KEY`, root disk eksplisit. AC-1/AC-7 dibuktikan lewat hasil run
  (skip di suite, evidence), bukan asersi Pest. Asersi file pihak kalah dipertahankan sebagai pengaman murah. AC-5 = uji
  asap 3 putaran.
