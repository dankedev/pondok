# F084 portal-wali-kirim-ulang-undangan — Kirim ulang undangan akun portal wali

Issue: #87
Status rencana: APPROVED (Review #1, minor dicatat)

Jalur penuh (Action baru yang membuat token password, config broker, activity log). Tindak lanjut F081 (#80).

## Ringkasan & tujuan

Admin yang boleh "Buat akun portal" dapat mengirim ulang email undangan (`GuardianPortalInvitation`, broker `guardian_invitations`, 72 jam) bagi wali yang punya akun tetapi belum pernah mengatur password. Token lama gugur, dibatasi throttle 60 detik, tercatat di activity log, ikut saklar modul Portal wali.

## Ruang lingkup

- Termasuk: Action `ResendGuardianPortalInvitation`; pengiriman token + notifikasi dipakai bersama dengan `CreateGuardianPortalAccount`; `throttle` 60 di broker undangan; aksi header di EditGuardian; kunci `lang/id/pondok.php`.
- Tidak termasuk (eksplisit):
  - Kolom penanda baru. Penanda "belum mengatur password" = `users.email_verified_at IS NULL` (alasan di Model data).
  - Kirim ulang untuk wali yang sudah mengatur password (mereka memakai "Lupa password"), aksi massal di tabel, dan pengubahan email akun wali dari halaman wali.
  - Pengecekan `users.is_active` (lihat Risiko). Antrean notifikasi (tetap sinkron seperti F081).

## Model data

- Tanpa migration. Penanda = `users.email_verified_at IS NULL`: akun wali dibuat F022 tanpa verifikasi; wali hanya tahu passwordnya lewat tautan atur password (undangan atau "Lupa password"), dan `PasswordReset` → `MarkEmailVerifiedOnPasswordReset` mengisi `email_verified_at`.
- `config/auth.php` `passwords.guardian_invitations`: tambah `'throttle' => 60`. Broker undangan tidak memakai `sendResetLink`, jadi throttle hanya dibaca lewat `getRepository()->recentlyCreatedToken($user)` di Action baru. AC-12 F081 memakai `toMatchArray`, jadi tetap hijau.
- `DatabaseTokenRepository::create` menghapus token lama email itu sebelum menyimpan yang baru, jadi token lama otomatis tidak berlaku tanpa kode tambahan.

## Action class

**Bagian bersama (DRY, dua pemakai nyata):** pindahkan isi `CreateGuardianPortalAccount::sendPasswordSetupLink` ke method statis `GuardianPortalInvitation::sendTo(User $user): void` (`createToken` di broker `self::BROKER`, lalu `$user->notify(new self($token))`). Alasan: paling sederhana, tidak ada Action memanggil Action, dan konstanta broker sudah tinggal di kelas itu. `CreateGuardianPortalAccount` memanggil `GuardianPortalInvitation::sendTo($user)`; perilaku dan test F081 tidak berubah.

`app/Actions/PortalWali/ResendGuardianPortalInvitation::handle(Guardian $guardian, User $actor): User`, urutan cek:
1. `Module::PortalWali->isEnabled()` salah → `module_disabled` (kunci ada).
2. `$guardian->user` null (user_id kosong atau user hilang) → `no_account` (baru).
3. `$user->hasVerifiedEmail()` → `already_set_password` (baru).
4. `Password::broker(GuardianPortalInvitation::BROKER)->getRepository()->recentlyCreatedToken($user)` → `invitation_throttled` (baru).
5. `GuardianPortalInvitation::sendTo($user)`, lalu `activity('akademik')->causedBy($actor)->performedOn($guardian)->event('updated')->withProperties(['action' => 'resend_portal_invitation', 'user_id' => $user->id])->log(__('pondok.portal_wali.activity_invitation_resent'))`. Log sesudah kirim: kiriman gagal (exception) tidak tercatat sebagai berhasil.
- Galat: `ValidationException::withMessages(['email' => __("pondok.portal_wali.errors.{$key}")])`, pola `reject()` seperti Create. Tanpa transaksi (tidak ada tulis ke tabel domain selain token dan log). Action tidak mengecek permission aktor, sama seperti Create; izin dicek di UI.

## UI (Filament)

`GuardianResource::resendPortalInvitationAction()`, dipasang di `EditGuardian::getHeaderActions()` sesudah `createPortalAccountAction()`:
- Label `portal_wali.resend_invitation` ("Kirim ulang undangan"), ikon `Heroicon::OutlinedEnvelope`, warna `gray` (aksi sekunder, design-guide §8).
- `visible`: `Module::PortalWali->isEnabled() && $record->user !== null && ! $record->user->hasVerifiedEmail() && static::canEdit($record)`.
- `requiresConfirmation()`; heading `resend_invitation_heading` ("Kirim ulang undangan ke :email?"); deskripsi `resend_invitation_confirm` menjelaskan akibat ("Tautan atur password baru dikirim ke email ini dan berlaku 72 jam. Tautan dari undangan sebelumnya tidak berlaku lagi."); submit `resend_invitation_submit` ("Kirim undangan").
- Sukses: Notification `success` `invitation_resent` ("Undangan dikirim ulang ke :email.").
- Galat: `runShowingFormErrors` tidak cocok (aksi tanpa form, galat tidak terlihat). Pakai pola `RoomsRelationManager`: tangkap `ValidationException` → Notification `danger` dengan pesan pertama, lalu `$action->halt()`.
- Teks admin tanpa sapaan, istilah email/password, tanpa tanda seru (§7, §9). Kunci galat baru:
  - `no_account`: "Wali ini belum punya akun portal. Buat akun portal dulu."
  - `already_set_password`: "Wali ini sudah mengatur password. Minta wali memakai Lupa password di halaman masuk portal."
  - `invitation_throttled`: "Undangan baru saja dikirim. Tunggu 1 menit sebelum mengirim ulang."
  - `activity_invitation_resent`: "Undangan akun portal wali dikirim ulang".

## Permission & cakupan

- Tanpa permission baru: `canEdit` (permission update Guardian; admin-sekolah punya, kesantrian hanya baca dan tidak bisa membuka halaman edit).
- Cakupan jenjang: `Guardian` tidak memakai global scope `unit_user` dan `GuardianResource` tidak menyaring per jenjang; Create juga tidak. Tidak ada perubahan.
- Saklar: resource sudah `BelongsToModule` (`akademik`); aksi menambah cek `Module::PortalWali`. `ModuleComplianceTest` memeriksa resource/page/widget, bukan aksi: tidak perlu entri baru.

## Kriteria penerimaan (bisa dites)

Semua AC: `Notification::fake()`, modul Portal wali aktif kecuali disebut lain, wali "tertunda" = punya akun via `CreateGuardianPortalAccount` dan `email_verified_at` null.

- **AC-1** Admin-sekolah membuka EditGuardian wali tertunda → aksi `resendPortalInvitation` terlihat (`assertActionVisible`).
- **AC-2** Aksi tersembunyi untuk: wali tanpa akun; wali yang sudah mengatur password (`email_verified_at` terisi); modul Portal wali mati. Kesantrian (Guardian hanya baca) mendapat 403 di halaman edit.
- **AC-3** Wali tertunda, token pertama dibuat lalu `travel(61)->seconds()`. Admin memanggil aksi → tepat satu `GuardianPortalInvitation` baru terkirim ke user itu (total dua termasuk dari Create), tidak ada `Illuminate\Auth\Notifications\ResetPassword`; satu baris di `guardian_invitation_tokens` untuk email itu; notifikasi `success` dengan email wali.
- **AC-4** Lanjutan AC-3: token lama + `undangan=1` (`waliReset(..., invitation: true)`) → galat `email` = `__('pondok.portal_wali.reset_invalid')`, password tidak berubah; token baru (dari notifikasi) + `undangan=1` → berhasil, `email_verified_at` terisi.
- **AC-5** Throttle: kirim ulang < 60 detik setelah token terakhir (termasuk tepat sesudah Create) → Action melempar galat `invitation_throttled`, tidak ada notifikasi tambahan, isi baris `guardian_invitation_tokens` (token hash dan `created_at`) tidak berubah, tidak ada activity log baru. Setelah `travel(61)->seconds()` → lolos.
- **AC-6** Lewat UI, kirim ulang yang terkena throttle → Notification `danger` berisi teks `invitation_throttled`, tidak ada `GuardianPortalInvitation` tambahan.
- **AC-7** Kirim ulang berhasil → satu activity log `log_name` `akademik`, description `activity_invitation_resent`, subject guardian, causer admin, properties `action` = `resend_portal_invitation` dan `user_id`; properties tidak memuat `token` atau `password`.
- **AC-8** Action dipanggil langsung (bypass UI) → `ValidationException` pada `email` dengan pesan yang sesuai dan tanpa notifikasi/token/log baru untuk: wali tanpa akun (`no_account`), wali yang sudah mengatur password lewat `waliReset` (`already_set_password`), modul mati (`module_disabled`).
- **AC-9** Wali tertunda yang memakai "Lupa password" lalu berhasil mengatur password → aksi tidak tampil lagi dan Action menolak `already_set_password` (penanda sama dengan jalur undangan).
- **AC-10** Regresi: `CreateGuardianPortalAccount` tetap mengirim tepat satu `GuardianPortalInvitation` lewat `GuardianPortalInvitation::sendTo` (test F081 di `CreateGuardianPortalAccountTest` hijau tanpa diubah); `config('auth.passwords.guardian_invitations.throttle')` = 60 dan `auth.passwords.users` tidak berubah.

## Rencana test

| AC | File | Nama test (usulan) |
|---|---|---|
| 3–5, 7–9 | `tests/Feature/Actions/PortalWali/ResendGuardianPortalInvitationTest.php` (baru) | `sends a fresh invitation and invalidates the old token`, `throttles a resend within 60 seconds`, `logs the resend without secrets`, `rejects guardians without an account, verified accounts and a disabled module`, `treats forgot-password as having set the password` |
| 1, 2, 6 | file yang sama, `describe('admin action')` | `shows the resend action only for pending wali accounts`, `hides the action when the module is off or the role cannot edit`, `shows a danger notification when throttled` |
| 10 | file yang sama + `CreateGuardianPortalAccountTest.php`, `GuardianInvitationTest.php` (tidak diubah) | `keeps the invitation broker config and adds a 60 second throttle` |

Pakai helper yang ada (`waliReset`, `waliAssertRejected` di `tests/Pest.php`) dan setup `beforeEach` gaya `CreateGuardianPortalAccountTest`. Token baru diambil dari `Notification::assertSentTo` (properti `$token`).

## Urutan implementasi

Paket 1 — Action (hijau: `ResendGuardianPortalInvitationTest` bagian Action, `CreateGuardianPortalAccountTest`, `GuardianInvitationTest`):
1. `config/auth.php` throttle 60.
2. `GuardianPortalInvitation::sendTo()`; `CreateGuardianPortalAccount` memakainya (hapus method privat).
3. `ResendGuardianPortalInvitation` + kunci galat/activity di `lang/id/pondok.php`.

Paket 2 — Filament (hijau: `describe('admin action')`, lalu `tests/Feature/PortalWali` dan `tests/Feature/Actions/PortalWali`):
4. `resendPortalInvitationAction()` + kunci UI; pasang di `EditGuardian`. Pint.

## Risiko & pertanyaan terbuka

- Mengganti email user mengosongkan `email_verified_at` (`User::booted`), jadi aksi tampil lagi untuk wali itu. Dapat diterima: undangan pergi ke email baru yang belum terbukti.
- `UserResource` (admin pengguna) bisa mengisi password wali tanpa memverifikasi email, sehingga aksi tetap tampil; mengirim undangan hanya memberi wali tautan atur password baru, tidak membuka akses baru. Admin juga bisa "Tandai email terverifikasi" (`MarkUserEmailVerified`) yang menyembunyikan aksi. Tidak ditangani.
- Akun wali nonaktif (`is_active` false) tetap bisa dikirimi undangan; password diatur tetapi masuk portal tetap ditolak. **Pertanyaan (user, tidak memblokir):** perlu ditolak juga? Default rencana: tidak (YAGNI).
- Dua kiriman bersamaan dalam milidetik bisa sama-sama lolos throttle; hanya token terakhir yang berlaku. Risiko rendah, tidak dikunci.
- Bila `notify` gagal (sinkron) setelah token dibuat, token lama sudah gugur dan throttle 60 detik berlaku, jadi admin menunggu semenit sebelum mencoba lagi. Diterima.

## Riwayat revisi

- 23 Okt 2026: DRAFT pertama.

## Review #1 — 2026-10-23

Verdict: APPROVED

Terverifikasi di kode: `PasswordBrokerManager` membaca `throttle` dari config (default `?? 0`, jadi menambah 60 memang perlu dan tidak mengubah broker `users`); `recentlyCreatedToken` memakai `created_at` dari baris token sehingga `travel()` bekerja; `DatabaseTokenRepository::create` memanggil `deleteExisting` per email sebelum insert, jadi token lama gugur; `ResetPassword` memilih broker lewat `undangan=1` sehingga AC-4 benar; `MarkEmailVerifiedOnPasswordReset` mengisi `email_verified_at`; `User::booted` mengosongkannya saat email berubah (sudah ada di Risiko); `runShowingFormErrors` ada di resource, pola `halt()` ada di `RoomsRelationManager`.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | AC-10 | "tepat satu notifikasi lewat `sendTo`" tidak bisa diassert; yang teruji hanya jumlah notifikasi. | Cukup assert jumlah notifikasi dan nilai config; hapus frasa "lewat sendTo" dari AC. |
| 2 | minor | Action, langkah 5 | Bila `notify` melempar (sinkron), token baru sudah dibuat, token lama gugur, dan throttle 60 detik berlaku; admin harus menunggu semenit. | Terima; sebut singkat di Risiko. |
| 3 | minor | AC-2 | Memuat tiga kasus tersembunyi plus 403 kesantrian dalam satu AC. | Pecah jadi beberapa `it` di test; tidak perlu AC baru. Pastikan test 403 benar-benar memakai peran kesantrian (read-only Guardian, seeder baris 244). |
| 4 | minor | Ruang lingkup | `sendTo` statis di kelas Notification mencampur tanggung jawab. | Dapat diterima (YAGNI/KISS, dua pemakai); jangan menambah Action pembungkus. |

Jumlah: blocker 0, major 0, minor 4.
