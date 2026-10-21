# F069 psb-wali-ganda-konkurensi — Cegah wali ganda saat konversi PSB bersamaan

Issue: #49
Status rencana: APPROVED (orkestrator, setelah revisi Review #1)
Jalur: penuh (PSB, Action yang mengubah data). Disetujui user 22 Okt 2026.

## Ringkasan & tujuan
`ResolveApplicantGuardian` melakukan cari-lalu-simpan wali tanpa kunci di dalam transaksi `ReRegisterApplicant`.
Dua daftar ulang saudara (wali sama) yang berjalan bersamaan bisa sama-sama tidak melihat wali baris lain (belum
commit, atau snapshot REPEATABLE READ) dan membuat dua baris `guardians`. F069 menyerialkan daftar ulang per nomor HP
wali dengan `Cache::lock` yang dipegang sampai transaksi selesai (commit/rollback).

## Keputusan (diambil atas nama user, opsi paling aman/YAGNI)
1. **Opsi: kunci per HP kanonik (`Cache::lock`)**, bukan unique key dan bukan aksi gabung wali.
   - Unique key ditolak: data `guardians` lama bisa sudah ganda (dibuat admin manual/impor), migrasinya tidak aman dan
     melanggar kriteria "data wali lama tidak diubah". Juga mengubah semantik "wali ganda lebih aman".
   - Kolom `phone_normalized` + `lockForUpdate` ditolak: butuh migrasi + backfill; `lockForUpdate` tidak mengunci
     baris yang belum ada (gap lock beda tiap DB, SQLite tanpa row lock).
   - Aksi gabung wali di admin = YAGNI (tidak diminta kriteria). Dicatat sebagai kandidat issue terpisah.
2. **Store**: store cache default. Produksi `CACHE_STORE=database` (tabel `cache_locks`, portable MySQL/SQLite/Postgres,
   cocok cPanel, preseden `GenerateMonthlyInvoices`). Kunci hanya efektif bila store terbagi antarproses (`database`
   atau `file` di satu server); `array` hanya untuk test proses tunggal.
3. **Kunci per HP saja (tanpa nama)**: KISS. Dua wali berbeda nama tapi HP sama hanya menunggu bergantian; hasil
   pencocokan tidak berubah.
4. **HP kanonik**: `^\+?62` → `0` setelah `trim` (sama dengan `SubmitApplication::phone`), sehingga varian
   `08…`/`628…`/`+628…` memakai kunci yang sama. Hanya untuk nama kunci; `phoneVariants` tetap.
   HP kosong (tidak mungkin dari formulir, wajib `08…`) tidak diberi perlakuan khusus: kuncinya `psb:guardian:`,
   akibatnya hanya saling menunggu. Tanpa cabang kode tambahan (KISS).
5. **HP kunci dibaca segar dari DB** (Review #1 major): `handle()` membaca
   `Applicant::withoutGlobalScopes()->withTrashed()->whereKey($applicant->id)->value('guardian_phone')`, bukan dari
   instance pemanggil (bisa basi dari render Filament). Di `convert()`, setelah `$locked` terkunci, bila
   `lockKey($locked->guardian_phone) !== $heldKey` → `fail('applicant', 'guardian_busy')` (HP diubah di sela; rollback,
   kunci dilepas, admin cukup mencoba lagi). Pendaftar tidak ada → `value()` null → kunci `psb:guardian:`, lalu
   `convert()` gagal `applicant_not_found` seperti sekarang.
6. **Waktu**: TTL `LOCK_SECONDS = 60` (jauh di atas durasi transaksi + salin berkas), tunggu `LOCK_WAIT_SECONDS = 10`.
   Timeout → `ValidationException` field `applicant`, pesan `pondok.psb.errors.guardian_busy`. Konstanta dibaca lewat
   `static::` agar test bisa mensubkelaskan dengan waktu tunggu 0.

## Ruang lingkup
- Termasuk: kunci di `ReRegisterApplicant`, helper `lockKey` di `ResolveApplicantGuardian`, satu teks `lang/id`, kasus
  baru di test MySQL dua proses (+ env `CACHE_STORE`), test SQLite kunci.
- Tidak termasuk (eksplisit): migrasi/unique key `guardians`; kolom HP ternormalisasi; aksi/halaman gabung wali ganda;
  perbaikan wali ganda yang sudah ada; perubahan aturan pencocokan (varian HP + nama); MySQL di CI; perubahan UI.

## Model data
Tidak ada perubahan skema. Memakai tabel `cache_locks` yang sudah ada (`0001_01_01_000001_create_cache_table.php`).

## Action class
- `app/Actions/Psb/ResolveApplicantGuardian.php`: tambah `public static function lockKey(?string $phone): string` →
  `'psb:guardian:'.<HP kanonik>`. `handle()`, `phoneVariants()`, `normalizeName()` tidak berubah. Docblock: pemanggil
  wajib memegang kunci ini; kunci butuh store cache terbagi antarproses (`database`/`file`).
- `app/Actions/Psb/ReRegisterApplicant.php::handle()`: setelah `normalize()` (validasi masukan tetap tanpa kunci),
  baca HP segar (Keputusan 5), `$key = lockKey($phone)`, lalu
  `Cache::lock($key, static::LOCK_SECONDS)->block(static::LOCK_WAIT_SECONDS, fn () => <loop percobaan yang ada>)`.
  Kunci diambil **di luar** transaksi dan dilepas `block()` (finally) **setelah** commit/rollback, termasuk saat
  exception. Tangkap `LockTimeoutException` → `fail('applicant', 'guardian_busy')`. `$key` diteruskan ke `convert()`
  untuk pemeriksaan Keputusan 5 (tepat setelah cek `already_converted`). Loop retry, kompensasi file, dan penanganan
  unique violation tidak berubah.
- Docblock kelas: tambah satu kalimat tentang kunci per HP wali.

## UI (Filament / Blade)
Tidak ada UI baru. Pemanggil (`ApplicantResource`, aksi daftar ulang) sudah menampilkan `ValidationException`.
Teks baru `lang/id/pondok.php` → `psb.errors.guardian_busy`: "Daftar ulang lain dengan nomor HP wali yang sama sedang
diproses. Coba lagi sebentar lagi."

## Permission & cakupan
Tidak berubah. Tidak ada permission baru, tidak menyentuh `unit_user` maupun saklar modul.

## Kriteria penerimaan (bisa dites)
Bukti RED (gagal sebelum perbaikan) hanya AC-1 dan AC-2; sisanya regresi/perilaku kunci.
- AC-1 (MySQL dua proses, inti issue): dua pendaftar saudara di **jenjang berbeda** (SD dan SLTP, agar tidak terserialkan
  oleh kunci angkatan/NIS), nama wali sama, HP `081234567890` dan `6281234567890`, tanpa wali lama; dua proses
  `hold_ms` 1500 → keduanya `ok`; tepat satu baris `guardians` dengan HP dalam varian itu; kedua santri terkait ke wali
  tersebut di `student_guardian`; satu activity `converted` ber-`guardian_reused` true dan satu false.
- AC-2 (MySQL, bukti menunggu): pada AC-1 proses yang kedua selesai ≥ 2400 ms sejak start (menunggu kunci lalu menahan
  sendiri); tidak ada error lain (deadlock, lock wait timeout).
- AC-3 (MySQL, regresi): test F053 lama tetap lulus. `f053Race` **wajib** memberi env `CACHE_STORE=database` (proses
  anak mewarisi `array` dari phpunit, kuncinya tidak terbagi); tidak memakai `config:cache`.
- AC-4 (SQLite, pencocokan tetap): `ResolveApplicantGuardianTest.php` dan `ReRegisterApplicantTest.php` lulus tanpa
  diubah (varian HP + nama, kandidat id terkecil, wali beda nama/HP tidak digabung).
- AC-5 (SQLite, kunci dilepas setelah sukses): daftar ulang berhasil → `Cache::lock(lockKey(hp), 1)->get()` true.
- AC-6 (SQLite, kunci dilepas setelah gagal): daftar ulang gagal di dalam transaksi (mis. pembayaran belum memenuhi
  syarat) → exception asli diteruskan, kunci bisa diambil lagi.
- AC-7 (SQLite, timeout): kunci `lockKey('081234567890')` sudah dipegang pemilik lain; daftar ulang (subkelas dengan
  `LOCK_WAIT_SECONDS = 0`, tanpa `Sleep::fake`) → `ValidationException` field `applicant` berisi
  `__('pondok.psb.errors.guardian_busy')`; tidak ada `students`, `guardians`, `student_guardian`, atau activity baru;
  tagihan pendaftar tetap `student_id` null.
- AC-8 (SQLite, kanonik): kunci dipegang dengan HP `+6281234567890` → pendaftar HP `081234567890` juga timeout;
  `lockKey('081234567890') === lockKey('6281234567890') === lockKey('+6281234567890')`.
- AC-9 (SQLite, beda HP tidak saling menunggu): kunci HP `081298765432` dipegang → daftar ulang pendaftar HP
  `081234567890` tetap berhasil.
- AC-10 (SQLite, validasi tanpa kunci): masukan invalid (mis. `relation` tak dikenal) saat kunci dipegang pemilik lain →
  error validasi aslinya (`relation_invalid`), bukan `guardian_busy`.
- AC-11 (SQLite, HP segar): instance pendaftar basi (atribut `guardian_phone` di memori diubah ke `081298765432`, DB
  tetap `081234567890`) → kunci memakai HP dari DB: kunci HP DB dipegang → timeout; kunci HP basi dipegang → berhasil.
- AC-12 (SQLite, HP berubah di sela): HP di DB diubah setelah dibaca `handle()` tetapi sebelum `$locked` dimuat
  (mis. `DB::listen` yang sekali meng-update `guardian_phone` setelah query baca HP) → `ValidationException`
  `guardian_busy`; tidak ada santri/wali baru; kunci dilepas.

## Rencana test
- `tests/Feature/Actions/Psb/ReRegisterConcurrencyMysqlTest.php` (+ `CACHE_STORE` di `f053Race`):
  `it('F069 AC-1/AC-2: creates one guardian when siblings in different units convert at once')`. AC-3 = test lama.
  Fixture `tests/Fixtures/f053-reregister.php` tidak diubah. Catatan: fixture membekukan jam (`Date::setTestNow`);
  `block()` dan TTL kunci `database` memakai waktu itu, jadi TTL praktis tidak kedaluwarsa dan tunggu kunci dibatasi
  oleh polling tanpa batas waktu nyata. Aman untuk test (pemegang kunci selalu selesai), jangan diandalkan untuk menguji
  timeout di MySQL.
- `tests/Feature/Actions/Psb/ReRegisterGuardianLockTest.php` (baru): AC-5..AC-12, satu `it` per AC
  (`releases the guardian lock after success`, `... after failure`, `rejects when the guardian lock is busy`,
  `uses one lock for all phone variants`, `does not wait for another guardian phone`, `validates input before locking`,
  `locks the stored guardian phone`, `rejects when the guardian phone changes before locking`).
- AC-4: jalankan file lama tanpa perubahan.

## Urutan implementasi
Paket 1 (satu pemanggilan green-coder) — hijau: `ReRegisterGuardianLockTest.php`, `ResolveApplicantGuardianTest.php`,
`ReRegisterApplicantTest.php`, `ReRegisterVerifyTest.php`, `ReRegisterApplicantStudentDataTest.php`.
1. `fix(psb)`: `ResolveApplicantGuardian::lockKey()` + docblock.
2. `fix(psb)`: teks `guardian_busy` di `lang/id/pondok.php`.
3. `fix(psb)`: kunci di `ReRegisterApplicant::handle()` (HP segar, konstanta, `LockTimeoutException`) + cek kunci di
   `convert()`.
4. Pint.
Paket 2 (verifikasi MySQL, oleh sesi utama bila env `F053_MYSQL_*` tersedia): `ReRegisterConcurrencyMysqlTest.php`
seluruhnya hijau; hasil (versi server, jumlah lulus) dicatat di `evidence`. Bila env tidak tersedia, catat di evidence
bahwa test di-skip dan RED tidak bisa dibuktikan di MySQL.

## Risiko & pertanyaan terbuka
- Kunci `database` kedaluwarsa (TTL 60 s) bila transaksi luar biasa lama → kembali ke perilaku lama (paling buruk wali
  ganda, bukan korup). Diterima.
- `CACHE_STORE=array` di produksi tidak mengunci antarproses (instalasi standar memakai `database`). Tidak ditangani.
- AC-1 butuh pendaftar SD yang lolos syarat bayar di fixture `daftarUlangApplicant(..., unit: 'SD')`. Bila tarif/tagihan
  SD tidak tersedia, red-tester boleh memakai jenjang lain; yang wajib: dua pendaftar tidak berbagi kunci angkatan/NIS.
- RED test MySQL hanya bisa dijalankan bila MySQL lokal tersedia; test di-skip di CI.
- Usulan issue terpisah (bukan dikerjakan): aksi gabung wali ganda di admin.
- Tidak ada pertanyaan terbuka yang memblokir.

## Review #1 — 2026-10-22
Verdict plan-reviewer: CHANGES_REQUESTED (0 blocker, 1 major, 5 minor). Penanganan:
- Major #1 (kunci dari instance basi, resolver memakai `$locked`): HP kunci dibaca segar dari DB; `convert()`
  membandingkan `lockKey($locked->guardian_phone)` dengan kunci yang dipegang, beda → `guardian_busy`. AC-11, AC-12 baru.
- Minor #2 (AC-2 jenjang sama bukan RED): dibuang (YAGNI); ditegaskan hanya AC-1/AC-2 (lama AC-1/AC-3) bukti RED.
- Minor #3 (jam beku di proses anak): dicatat di Rencana test; AC-7 tanpa `Sleep::fake`.
- Minor #4 (HP kosong): tanpa cabang khusus, dicatat di Keputusan 4.
- Minor #5 (`CACHE_STORE=database` di `f053Race`): ditegaskan wajib, tanpa `config:cache` (AC-3).
- Minor #6 (docblock store terbagi): ditambahkan di Keputusan 2 dan `ResolveApplicantGuardian`.

## Riwayat revisi
- 22 Okt 2026: DRAFT pertama.
- 22 Okt 2026: Revisi 1 atas Review #1 (lihat bagian di atas); AC dinomori ulang 1–12.
