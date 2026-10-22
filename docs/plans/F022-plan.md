# F022 portal-wali — Portal wali

Issue: #3
Status rencana: APPROVED (orkestrator, setelah revisi Review #1)

## Ringkasan & tujuan
Wali (peran `wali`) login ke portal untuk melihat santri yang terhubung dengannya, tagihan santri itu (status, rincian, riwayat pembayaran), dan dokumen santri (unduh dari disk `private`). Hanya baca; tanpa pembayaran online (F020 ditunda), tanpa WhatsApp (F021 ditunda).

## Keputusan (atas nama user, dari arahan orkestrator)
- **K1 Teknologi: panel Filament kedua `wali` (path `/wali`)**, bukan Blade+Livewire buatan sendiri (orkestrator: tetap, sudah ditanyakan ke user). Alasan: login, throttle, lupa password, verifikasi email, MFA (F055), dan profil sudah teruji di Filament; membangun ulang di Blade menambah permukaan serangan auth (KISS). Tanpa dependensi baru. Penyesuaian: `->darkMode(false)` (portal wali hanya mode terang), `->maxContentWidth(Width::TwoExtraLarge)` (setara `max-w-2xl`), `->topNavigation()`, tema Vite admin dipakai ulang, tanpa Dashboard bawaan Filament, `AccountWidget`, maupun `databaseNotifications`. design-guide §0, §2, §6 diperbarui (portal wali = panel Filament); orkestrator mengusulkan perubahan baris stack CLAUDE.md ke user.
- **K2 Akun wali dibuat admin** (pemegang `update:guardian`) lewat aksi "Buat akun portal" di halaman ubah wali: isi email → user baru berperan `wali` + tautan atur password panel `wali`. Tanpa registrasi mandiri. Email yang sudah dipakai user lain ditolak. **`guardians.user_id` hanya diisi oleh `CreateGuardianPortalAccount`**: Select `user_id` di `GuardianForm` dihapus (diganti teks read-only email akun tertaut di halaman ubah), `user_id` dikeluarkan dari `#[Fillable]` Guardian (tidak ada pemakai lain: PSB, impor, relation manager, factory, seeder tidak mengisinya). Mencabut akses: super-admin menonaktifkan user di Pengguna (`is_active`).
- **K3 Verifikasi email:** panel `wali` memakai `->emailVerification()`. Listener `PasswordReset` menandai email terverifikasi bila belum (membuka tautan reset membuktikan kepemilikan email). Dampak ke staf: staf belum terverifikasi yang berhasil reset password juga jadi terverifikasi; diterima karena buktinya sama kuat dengan tautan verifikasi.
- **K4 MFA aplikasi opsional** di panel `wali` (pola F055, `isRequired: false`), `->profile()` + `->emailChangeVerification()`. Banner MFA tidak tampil untuk wali.
- **K5 Satu pintu query, kepemilikan = satu-satunya sumber akses.** Scope jenjang (`UnitScope`) ada di Student, Invoice, Payment, Document, RoomAssignment, dst. dan membaca `scopedUnitIds()` user login, jadi wali dengan baris `unit_user` bisa tersaring keliru. Mekanisme (wajib, tanpa query portal lain):
  - `User::portalStudents(): Builder` = `Student::withoutUnitScope()->whereHas('guardians', fn => user_id = $this->id)` (SoftDeletes tetap aktif → santri diarsipkan tidak terlihat).
  - `User::portalStudent(int $id): Student` = `portalStudents()->findOrFail($id)`.
  - `User::portalInvoices(Student $s)` = `Invoice::withoutUnitScope()->where('student_id', $s->id)->where('status', '!=', 'batal')`; `portalInvoice(int $id)` = `Invoice::withoutUnitScope()->whereIn('student_id', portalStudents()->select('id'))->where('status','!=','batal')->findOrFail($id)`.
  - Pembayaran: `Payment::withoutUnitScope()->where('invoice_id', $invoice->id)->where('status', 'diterima')`.
  - Dokumen: `Document::withoutUnitScope()->where('documentable_type', 'student')->whereIn('documentable_id', portalStudents()->select('id'))->whereNull('replaced_by_id')->whereHas('documentType', is_confidential = false)`; `portalDocument(int $id)` memakai query yang sama + `findOrFail`.
  - Rombel/kamar: eager load dari santri portal dengan `withoutGlobalScopes` pada relasi ber-scope (`activeRoomAssignment`/`activeRoom`, `currentEnrollment.classroom`).
  - Semua kasus tidak berhak (id tak ada, milik wali lain, arsip, relasi dilepas, tagihan `batal`) → 404 seragam (`ModelNotFoundException`).
- **K6 Yang ditampilkan:** santri = nama, NIS, status, jenjang, rombel aktif, kamar aktif (hanya bila Asrama aktif). Tanpa NIK/KK/NISN/alamat. Tagihan non-`batal`, pembayaran `diterima`, dokumen santri current non-rahasia (semua status sebagai badge). Dokumen wali/prestasi, kuitansi cetak, bukti transfer tidak termasuk.

## Ruang lingkup
- Termasuk: modul `portal-wali`, panel `wali`, 3 halaman (Beranda, Santri, Tagihan), route unduh dokumen, Action buat akun + aksi Filament, penghapusan Select `user_id`, listener reset password, `lang/id`, update design-guide.
- Tidak termasuk (eksplisit): pembayaran online, notifikasi/WA, unggah dokumen oleh wali, perizinan, tahfidz (F023 boleh menambah), kuitansi PDF, kirim ulang tautan oleh admin (wali memakai "Lupa password"), menautkan email user lama, memindahkan/melepas akun dari wali, registrasi mandiri.

## Model data
Tanpa migration. `guardians.user_id` (nullable, unique, `nullOnDelete`) sudah ada; keluar dari `#[Fillable]`, diisi lewat `forceFill`.
- `User::guardian(): HasOne` + method portal pada K5 (satu titik, DRY).

## Action class
`app/Actions/PortalWali/CreateGuardianPortalAccount::handle(Guardian $guardian, string $email, User $actor): User`
- Normalisasi `$email = Str::lower(trim($email))`; validasi format.
- Tolak (`ValidationException` pada `email`) bila: modul `portal-wali` mati, email tidak valid, `users` sudah punya email itu (bandingkan `LOWER(email)`), atau guardian sudah punya `user_id`.
- Transaksi: `Guardian::lockForUpdate()->find()` lalu cek ulang `user_id`; buat User (`name` = nama wali, password acak 40 karakter, `is_active` true, `email_verified_at` NULL), `assignRole('wali')`, `forceFill(['user_id'])`. `UniqueConstraintViolationException` (email/user_id balapan) → `ValidationException` pada `email`.
- Setelah commit: kirim `Filament\Auth\Notifications\ResetPassword` dengan URL `Filament::getPanel('wali')->getResetPasswordUrl($token, $user)` (token dari password broker panel `wali`).
- `activity('akademik')` performedOn guardian, causedBy actor, pesan `pondok.portal_wali.activity_account_created` (tanpa password/token).

Listener `app/Listeners/MarkEmailVerifiedOnPasswordReset` (event `Illuminate\Auth\Events\PasswordReset`): `markEmailAsVerified()` bila belum.

## UI (Filament / Blade)
Provider `app/Providers/Filament/WaliPanelProvider.php` (didaftarkan di `bootstrap/providers.php`): id `wali`, path `wali`, `login()`, `passwordReset()`, `profile()`, MFA opsional, `emailVerification()`, `emailChangeVerification()`, `darkMode(false)`, `topNavigation()`, lebar 2XL, discover hanya `app/Filament/Wali/Pages`. Middleware panel ditambah `EnsureModuleIsEnabled::class.':portal-wali,404'`. Route terautentikasi + email terverifikasi: `documents/{document}/download` `->whereNumber('document')` + `EnsureModuleIsEnabled:dokumen,404`, name `filament.wali.dokumen.download`.
- Setiap halaman: `getModule(): 'portal-wali'`; `canAccess()` cek modul `portal-wali` (dan Keuangan untuk Tagihan), karena `/livewire/update` tidak membawa middleware modul. Properti Livewire hanya menyimpan id (int); record di-resolve ulang lewat method K5 di setiap render/aksi.
- `Pages/Dashboard` (Beranda, slug kosong): kartu per santri (nama, NIS, jenjang, rombel, kamar bila Asrama aktif, sisa tagihan bila Keuangan aktif) → tautan ke Santri. Empty state: "Belum ada santri yang terhubung dengan akun Anda. Hubungi admin pondok."
- `Pages/ShowStudent` (`santri/{student}`): data santri; bagian Tagihan (tabel berhalaman: nomor, periode/kelompok, total, sisa, jatuh tempo, badge status) bila Keuangan aktif; bagian Dokumen (jenis, berlaku s.d., badge status, tombol Unduh; nomor tidak ditampilkan) bila Dokumen aktif. Empty state masing-masing.
- `Pages/ShowInvoice` (`tagihan/{invoice}`): item (`base_amount`, `discount_amount`, `amount`), total, sisa, pembayaran `diterima` (tanggal, metode, nominal).
- Controller `app/Http/Controllers/PortalWali/DownloadStudentDocument` (`int $document`): `portalDocument()` lalu `DownloadDocument::respond(Document)`. `respond()` diekstrak dari controller admin apa adanya (cek file ada → 404, nama berkas, header `nosniff` + `private, no-store`); tidak ada logika tambahan.
- Aksi admin `GuardianResource::createPortalAccountAction()` di header EditGuardian: terlihat bila modul `portal-wali` aktif, `user_id` kosong, dan user boleh `update` guardian; form email; konfirmasi menampilkan email; notifikasi sukses.
- Format §5 (uang `Rp 1.250.000`, tanggal `15 Okt 2026`), status tagihan dari `Invoice::statusColor/statusLabel`. Teks di `lang/id/pondok.php` (`portal_wali.*`, `modules.portal-wali`), sapaan "Anda", Heroicon Outlined. Kontrak desain §13 di PR; cek 360px.

## Permission & cakupan
- Tanpa permission baru. Peran `wali` tetap `[]`.
- `User::canAccessPanel`: `admin` tetap `is_active && can('view-dashboard')`; `wali` = `is_active && hasRole('wali')`. Staf yang juga berperan wali boleh masuk keduanya; di panel wali hanya melihat santri tautannya (K5).
- `Module::PortalWali = 'portal-wali'`, `requires()` = `[Akademik]`. Keuangan/Dokumen/Asrama tidak wajib; bagiannya disembunyikan saat mati. `SettingSeeder` menyalakannya di instalasi baru; instance lama mati sampai dinyalakan super-admin.

## Kriteria penerimaan (bisa dites)
Test panel wali memakai `Filament::setCurrentPanel('wali')` (Livewire) atau HTTP ke `/wali/...`.
- AC-1 Modul: `Module::PortalWali` ada, `requires()` = `[Akademik]`, label/deskripsi `lang/id` terisi; `ModuleComplianceTest` memeriksa setiap halaman panel `wali` ber-`getModule()` `portal-wali` dan middleware panel memuat `EnsureModuleIsEnabled:portal-wali,404`.
- AC-2 Modul mati: `/wali/login`, Beranda, Santri, Tagihan, unduh dokumen → 404 (juga untuk wali login); `canAccess()` halaman false; aksi "Buat akun portal" tersembunyi dan Action menolak.
- AC-3 Akses panel: wali aktif → Beranda 200; wali buka `/admin` → 403; dataset semua peran bawaan non-wali (`super-admin`, `bendahara`, `admin-konten`, `admin-sekolah`, `admin-psb`, `kesantrian`, `musyrif`, `ustadz-tahfidz`, `petugas-pos`) → 403 di `/wali`; wali `is_active = false` → 403; tamu → redirect `/wali/login`; staf `bendahara` + `wali` → 200 di keduanya dan Beranda hanya memuat santri tautannya.
- AC-4 Beranda: wali dengan dua santri bersaudara melihat keduanya, tidak melihat santri wali lain; jenjang, rombel, sisa tagihan `Rp 1.250.000` tampil; kamar tampil saat Asrama aktif dan hilang saat mati. Wali tanpa baris guardian atau tanpa santri → empty state, 200.
- AC-5 Lintas wali: wali A membuka Santri, Tagihan, dan unduh dokumen milik santri wali B → 404 (ketiganya); id tidak ada → 404 sama; route unduh dengan id non-angka → 404.
- AC-6 Arsip & lepas relasi: santri di-soft-delete atau `student_guardian` dilepas → hilang dari Beranda; Santri/Tagihan/dokumennya → 404.
- AC-7 Scope jenjang tidak mempengaruhi wali: wali yang punya baris `unit_user` jenjang lain tetap melihat santrinya, rombel, kamar, tagihan + pembayarannya, dan dokumennya (daftar dan unduh 200), serta tetap tidak melihat data wali lain.
- AC-8 Halaman Santri: tagihan `batal` tidak tampil; total/sisa/status benar; NIK/NISN/alamat tidak ada di HTML. Keuangan mati → bagian Tagihan tidak dirender, halaman 200, Tagihan → 404. Dokumen mati → bagian Dokumen tidak dirender.
- AC-9 Halaman Tagihan: item dengan `base_amount`/`discount_amount`/`amount`, sisa, pembayaran `diterima` tampil; pembayaran `batal` tidak tampil; tanggal `15 Okt 2026`; tagihan `batal` milik santri sendiri → 404.
- AC-10 Dokumen: daftar hanya dokumen current milik santri dengan jenis non-rahasia (versi lama, jenis rahasia, dokumen milik guardian tidak tampil). Unduh dokumen sendiri → 200, `Content-Disposition` attachment, `Cache-Control` memuat `no-store`, dari disk `private` (`Storage::fake('private')`); versi lama, jenis rahasia, dokumen guardian, file hilang → 404.
- AC-11 Buat akun: admin-sekolah menjalankan aksi di EditGuardian dengan `Wali@Contoh.test` → user baru email `wali@contoh.test`, peran `wali`, `email_verified_at` NULL, `guardians.user_id` terisi, satu `ResetPassword` notification dengan URL berawalan `/wali/`, satu activity log `akademik` tanpa password/token. Kesantrian (Guardian read-only) tidak melihat aksi.
- AC-12 Buat akun gagal: email sudah dipakai (beda huruf besar pun), email tidak valid, guardian sudah punya akun → `ValidationException` pada `email`, tanpa user/notifikasi baru; aksi tersembunyi bila sudah punya akun.
- AC-13 `user_id` terkunci: form EditGuardian/CreateGuardian tidak punya field `user_id`; mengirim `user_id` lewat form (`fillForm`/`call('save')`) atau `Guardian::fill(['user_id' => …])` tidak mengubah `guardians.user_id`; halaman ubah menampilkan email akun tertaut (read-only).
- AC-14 Reset password: lewat halaman `ResetPassword` panel `wali` dengan token valid → password berubah dan `email_verified_at` terisi, lalu Beranda 200 tanpa prompt verifikasi; token salah → gagal dan `email_verified_at` tetap NULL. User terverifikasi tidak berubah tanggalnya.

## Rencana test
- `tests/Feature/PortalWali/PortalWaliModuleTest.php` — AC-1, AC-2; + satu test di `tests/Feature/ModuleComplianceTest.php` (`AC-9: every wali panel page declares portal-wali and panel is guarded`).
- `tests/Feature/PortalWali/WaliPanelAccessTest.php` — AC-3, AC-7.
- `tests/Feature/PortalWali/WaliPagesTest.php` — AC-4, AC-5, AC-6, AC-8, AC-9.
- `tests/Feature/PortalWali/DownloadStudentDocumentTest.php` — AC-5 (dokumen), AC-10.
- `tests/Feature/Actions/PortalWali/CreateGuardianPortalAccountTest.php` — AC-11, AC-12, AC-13, AC-14.
- Regresi: `ModuleComplianceTest.php`, `ModulesDisabledSmokeTest.php`, `tests/Feature/Http/Dokumen/DocumentDownloadTest.php`, test GuardianResource yang ada.

## Urutan implementasi
Paket 1 — modul, model, Action (hijau: PortalWaliModuleTest AC-1, CreateGuardianPortalAccountTest AC-11/12 bagian Action, AC-13 bagian model)
1. `Module::PortalWali` + `requires()` + lang `modules.portal-wali`.
2. `User::guardian()` + method portal K5; `canAccessPanel` per panel; `user_id` keluar dari Fillable.
3. `CreateGuardianPortalAccount` + listener `MarkEmailVerifiedOnPasswordReset` (+ lang activity).

Paket 2 — panel & halaman (hijau: WaliPanelAccessTest, WaliPagesTest, PortalWaliModuleTest AC-2, ModuleComplianceTest, AC-14)
4. `WaliPanelProvider` + registrasi provider + middleware modul.
5. Halaman Beranda, Santri, Tagihan (`canAccess`, id-only state) + lang `portal_wali.*`.

Paket 3 — dokumen & admin (hijau: DownloadStudentDocumentTest, CreateGuardianPortalAccountTest lengkap, DocumentDownloadTest)
6. Ekstrak `DownloadDocument::respond()`; controller `DownloadStudentDocument` + route panel.
7. Hapus Select `user_id` dari `GuardianForm`, tampilkan email tertaut read-only; aksi `createPortalAccountAction()`.
8. Update design-guide §0, §2, §6; Pint; cek tampilan 360px/1024px.

## Risiko & pertanyaan terbuka
- Usulan perubahan baris stack CLAUDE.md (portal wali = panel Filament) menunggu persetujuan user lewat orkestrator.
- Email salah ketik dari admin → tautan ke orang lain. Mitigasi: konfirmasi menampilkan email; super-admin bisa menonaktifkan user.
- Tagihan santri yang diarsipkan tidak terlihat wali (K5); ditangani admin di luar portal.
- Tautan reset kedaluwarsa (`auth.passwords.users.expire`, 60 menit): wali memakai "Lupa password".

## Riwayat revisi
- DRAFT 1 (22 Okt 2026): rencana awal.
- Revisi 1 (22 Okt 2026): menjawab Review #1, status APPROVED (orkestrator, setelah revisi Review #1).

### Review #1 (plan-reviewer: CHANGES_REQUESTED, 2 major, 7 minor)
| # | Tingkat | Temuan | Penanganan |
|---|---|---|---|
| 1 | major | Select `user_id` di GuardianForm memungkinkan pemegang `update:guardian` menautkan/memindahkan akun dan menelusuri email semua user | K2: Select dihapus, diganti email read-only; `user_id` keluar dari Fillable (tanpa pemakai lain); tautan hanya lewat Action; AC-13 |
| 2 | major | Scope jenjang juga ada di Invoice, Payment, Document, RoomAssignment, enrollments | K5: satu pintu query `User::portal*()` dengan `withoutUnitScope` per model, filter `student_id` ∈ santri wali, Document `documentable_type = 'student'` + `documentable_id`; AC-7 diperluas |
| 3 | minor | Tagihan batal & pembayaran batal | `portalInvoice()` lewat santri milik wali, `batal` → 404; pembayaran hanya `diterima` (AC-9) |
| 4 | minor | State Livewire & `/livewire/update` tanpa middleware modul | Id-only state, resolve ulang tiap render; `canAccess()` cek modul (AC-2) |
| 5 | minor | Dampak listener ke staf; test lewat halaman reset | Dicatat di K3; AC-14 lewat halaman ResetPassword, token salah |
| 6 | minor | Email case-insensitive, race | Lower + `lockForUpdate` + unique violation → ValidationException (Action, AC-12) |
| 7 | minor | Panel wali minimal, design-guide konsisten, `setCurrentPanel` | K1 tanpa Dashboard bawaan/AccountWidget/notifikasi DB; §0, §2, §6 diperbarui; catatan test di AC |
| 8 | minor | `whereNumber`, ekstraksi `respond()` KISS | Route `whereNumber`; `respond()` dipindah apa adanya (AC-5) |
| 9 | minor | Dataset semua peran non-wali; staf yang juga wali | AC-3 diperluas |
