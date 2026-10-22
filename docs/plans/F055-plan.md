# F055 inti-mfa-verifikasi-email — MFA/2FA & verifikasi email

Issue: #21
Status rencana: APPROVED (orkestrator, setelah revisi Review #1)

## Ringkasan & tujuan
Menutup temuan audit ISO A.8.5 (tanpa MFA) dan A.5.16 (email tidak diverifikasi), rekomendasi #3 dan #10 di `docs/audits/iso-27001-annex-a-audit.md`. MFA aplikasi (TOTP) bawaan Filament 5.10 bersifat opsional untuk semua pengguna panel. Super-admin dan bendahara tanpa MFA mendapat peringatan persisten. Super-admin bisa mereset MFA pengguna lain; operator server bisa mereset lewat CLI. Verifikasi email diwajibkan tanpa mengunci akun yang sudah ada, dengan jalan keluar tanpa SMTP. Arahan user: komentar issue #21 (22 Okt 2026). **Tanpa dependensi composer baru** (`pragmarx/google2fa` dan `google2fa-qrcode` sudah terpasang lewat Filament).

## Ruang lingkup
- Termasuk: kolom MFA dan migrasi data `email_verified_at`; kontrak MFA di `User`; `->multiFactorAuthentication(...)`, `->emailVerification()`, `->emailChangeVerification()` di panel admin; middleware verifikasi email pada route kustom panel; banner peringatan; aksi "Reset 2FA" dan "Tandai email terverifikasi" di halaman ubah pengguna; command `pondok:reset-mfa`; `CreateSuperAdmin` menjamin akun terverifikasi.
- Tidak termasuk (eksplisit): MFA email OTP (`EmailAuthentication`) karena butuh SMTP andal dan pengguna terkunci bila SMTP mati; MFA wajib; portal wali (F022, cukup memakai kontrak yang sama nanti); kolom "2FA aktif" di tabel pengguna; aksi admin "kirim ulang email verifikasi" (pengguna bisa kirim ulang dari halaman prompt Filament); log aktivitas saat pengguna sendiri mengaktifkan/menonaktifkan MFA.

## Model data
Tabel `users` (inti, tanpa soft delete), migration skema `2026_10_28_000000_add_app_authentication_to_users_table`:
- `app_authentication_secret` `text` nullable, cast `encrypted`.
- `app_authentication_recovery_codes` `text` nullable, cast `encrypted:array` (isinya hash bcrypt dari Filament).
- Cast dan `hidden` sudah digabung otomatis oleh trait Filament; jangan diduplikasi di `casts()`.
Migration data `2026_10_28_000001_mark_existing_users_email_verified`: `update(['email_verified_at' => now()])` untuk baris `whereNull('email_verified_at')` lewat query builder (kompatibel SQLite/MySQL/Postgres). `down()` kosong (tidak bisa membedakan mana yang ditandai; dicatat di komentar).
Perbarui `docs/erd.md` entitas `users`: `email_verified_at`, `app_authentication_secret`, `app_authentication_recovery_codes`.

`App\Models\User`:
- implements `MustVerifyEmail`, `HasAppAuthentication`, `HasAppAuthenticationRecovery`; use `InteractsWithAppAuthentication`, `InteractsWithAppAuthenticationRecovery`.
- Status "punya MFA" selalu `filled($user->getAppAuthenticationSecret())` (method kontrak Filament); tidak ada helper buatan sendiri.
- `shouldBeWarnedToEnableMultiFactor(): bool` = `hasAnyRole(['super-admin','bendahara']) && blank($this->getAppAuthenticationSecret())`.
- `booted()` hook `updating`: bila `email` berubah dan `email_verified_at` tidak ikut diubah, set `email_verified_at = null`. Dipilih di model (bukan `EditUser`) agar semua jalur ubah email tercakup; alur ganti email Filament (`update(email)` lalu `markEmailAsVerified()`) tetap berakhir terverifikasi (AC-13).

## Action class
`app/Actions/Core/ResetUserMultiFactor::handle(?User $actor, User $target): void`
- `$actor = null` berarti pemanggilan sistem dari CLI (operator server); tanpa cek peran, causer kosong.
- Bila `$actor` diisi: `AuthorizationException` bila bukan peran `super-admin` (pola `SetModuleEnabled`); `ValidationException` bila `$target` adalah actor sendiri (diri sendiri menonaktifkan lewat profil, yang meminta kode).
- `ValidationException` bila target belum punya MFA (berlaku juga dari CLI).
- Dalam transaksi: `forceFill` kedua kolom MFA ke `null`, simpan; `activity('pengaturan')->causedBy($actor)` (dilewati bila null) `->performedOn($target)->event('updated')->withProperties(['action' => 'reset_multi_factor', 'via' => 'panel'|'cli'])->log(__('pondok.user.security.activity_mfa_reset', ['name' => ...]))`. Properti tidak memuat secret atau recovery code.

`app/Actions/Core/MarkUserEmailVerified::handle(User $actor, User $target): void`
- Super-admin saja (`AuthorizationException`); `ValidationException` bila sudah terverifikasi.
- `markEmailAsVerified()` + `activity('pengaturan')` (event `updated`, properti `['action' => 'mark_email_verified']`).

`app/Console/Commands/ResetMultiFactorCommand` `#[Signature('pondok:reset-mfa {email}')]` (pola `pondok:backup`/`pondok:doctor`): cari user by email; tidak ada → pesan error, exit 1; panggil `ResetUserMultiFactor::handle(null, $user)`; `ValidationException` (tanpa MFA) → pesan error, exit 1; sukses → pesan info, exit 0. Teks lewat `lang/id`. Untuk super-admin tunggal yang kehilangan HP dan kode pemulihan.

`CreateSuperAdmin`: akun baru sudah terverifikasi (tetap); akun yang sudah ada dan belum terverifikasi ikut ditandai terverifikasi saat diberi peran super-admin (jalan keluar CLI `pondok:install` bila SMTP mati). Nama dan password tetap tidak diubah.

## UI (Filament / Blade)
- `AdminPanelProvider`: `->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: false)`, `->emailVerification()` (wajib), `->emailChangeVerification()`, `->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('filament.mfa-warning'))`. Grup `authenticatedRoutes` dibungkus middleware verifikasi email bawaan panel (`$panel->getEmailVerifiedMiddleware()`, yaitu `verified:<route prompt>`).
- `resources/views/filament/mfa-warning.blade.php`: tampil hanya bila `auth()->user()?->shouldBeWarnedToEnableMultiFactor()`. Kotak warna token `warning` (§2), ikon `heroicon-o-shield-exclamation` (§3), judul + satu kalimat akibat + tautan "Aktifkan 2FA" ke `Filament::getProfileUrl()`. Tanpa sapaan, tanpa seru (§9). Halaman profil adalah simple page sehingga banner tidak muncul di sana.
- `EditUser` header actions (hanya super-admin, bukan record sendiri):
  - `resetMultiFactor` "Reset 2FA", `danger`, `requiresConfirmation`, deskripsi: "2FA pengguna dinonaktifkan dan kode pemulihan lama tidak berlaku. Pengguna dapat mengaktifkannya kembali dari profil." Visible bila target punya MFA. Memanggil `ResetUserMultiFactor`; notifikasi success menyebut nama.
  - `markEmailVerified` "Tandai email terverifikasi", `gray`, `requiresConfirmation`, visible bila target belum terverifikasi. Memanggil `MarkUserEmailVerified`.
- `UserForm`: field `email` disabled untuk record milik sendiri (pola toggle `is_active`), helper "Ubah email Anda lewat halaman profil." Saat create, helper email: "Pengguna baru harus memverifikasi email saat login pertama. Bila email tidak bisa dikirim, super-admin dapat memakai aksi Tandai email terverifikasi."
- Semua teks di `lang/id/pondok.php` kunci `user.security.*`, `user.email_*_helper`, `user.reset_mfa_command.*`; terjemahan vendor Filament `id` untuk MFA/verifikasi dipakai apa adanya.

## Permission & cakupan
- Tanpa permission baru: reset MFA dan tandai email adalah operasi keamanan akun, dicek `hasRole('super-admin')` di Action dan `visible()` (konsisten `SetModuleEnabled`; Shield bisa memberi `update:user` ke peran lain, tetapi aksi ini tetap khusus super-admin). CLI hanya bisa dijalankan operator yang punya akses server.
- Inti, bukan modul: tanpa saklar `modules.*`; `UserResource` sudah di daftar inti `ModuleComplianceTest`; render hook, command, dan route Filament (vendor) tidak perlu didaftarkan.
- Portal wali (F022): kontrak MFA dan `MustVerifyEmail` ada di `User`, sehingga portal bisa memakai `AppAuthentication` yang sama; tidak dibangun di sini.

## Kriteria penerimaan (bisa dites)
- AC-1: Dua user lama (satu `email_verified_at` NULL, satu terisi tanggal lama) → jalankan migration data → keduanya terverifikasi; tanggal lama tidak berubah. Migration skema menambah kedua kolom nullable.
- AC-2: Simpan secret dan recovery codes lewat method kontrak → nilai mentah di DB (`DB::table('users')`) tidak memuat plaintext; `decrypt` sama dengan secret; recovery code tersimpan sebagai hash; kedua kolom tidak ada di `toArray()`.
- AC-3: Login via `Filament\Auth\Pages\Login`: user tanpa MFA langsung terautentikasi (panel tidak mewajibkan MFA); user ber-MFA → form tantangan, kode salah ditolak dan tetap tamu, kode TOTP benar (`AppAuthentication::make()->getCurrentCode($user)`) → terautentikasi.
- AC-4: Recovery code valid saat tantangan → terautentikasi dan jumlah kode berkurang satu; kode yang sama ditolak pada login berikutnya. `EditProfile` menampilkan pengelolaan aplikasi autentikator.
- AC-5: `get('/admin')` sebagai super-admin dan bendahara tanpa MFA → `assertSee` judul banner dan URL profil; dengan MFA → `assertDontSee`; peran lain (admin-konten, admin-sekolah, admin-psb, kesantrian, musyrif, ustadz-tahfidz, petugas-pos) tanpa MFA → `assertDontSee`.
- AC-6: Super-admin memanggil `ResetUserMultiFactor` untuk user lain ber-MFA → kedua kolom NULL; satu activity `log_name = pengaturan`, causer actor, subject target; properti dan deskripsi tidak memuat secret atau recovery code.
- AC-7: `ResetUserMultiFactor` ditolak bila actor bukan super-admin (bendahara, atau peran kustom ber-`update:user`), target = actor, atau target tanpa MFA → data tidak berubah, tidak ada activity baru.
- AC-8: `artisan pondok:reset-mfa <email>` untuk super-admin ber-MFA → exit 0, kolom MFA NULL, activity `pengaturan` dengan causer NULL dan `via = cli`. Email tidak dikenal atau user tanpa MFA → exit 1, tidak ada activity.
- AC-9: `EditUser` sebagai super-admin untuk user ber-MFA → aksi `resetMultiFactor` terlihat, wajib konfirmasi, setelah dipanggil MFA kosong dan notifikasi success. Tersembunyi untuk target tanpa MFA, record sendiri, dan peran kustom ber-`update:user` yang bukan super-admin.
- AC-10: User belum terverifikasi yang login → `get('/admin')` dan salah satu route `authenticatedRoutes` (mis. `filament.admin.keuangan.receipt`) diarahkan ke route prompt verifikasi email; user terverifikasi tidak.
- AC-11: User dibuat lewat `CreateUser` → `email_verified_at` NULL. Super-admin mengubah email user lain di `EditUser` → `email_verified_at` NULL; mengubah nama saja → tetap terverifikasi. Field email disabled saat super-admin mengedit dirinya sendiri.
- AC-12: `MarkUserEmailVerified` oleh super-admin → `email_verified_at` terisi, activity `pengaturan` tercatat; aksi `markEmailVerified` di `EditUser` hanya untuk super-admin pada user belum terverifikasi dan wajib konfirmasi; bukan super-admin → `AuthorizationException`.
- AC-13: Ganti email di profil (`Notification::fake`) → email di DB belum berubah dan `VerifyEmailChange` terkirim; setelah URL verifikasi ganti email dibuka → email baru tersimpan dan `email_verified_at` tidak NULL (hook `updating` tidak merusak alur Filament).
- AC-14: `CreateSuperAdmin` membuat akun baru terverifikasi; email yang sudah ada dan belum terverifikasi menjadi terverifikasi setelah dipromosikan, nama dan password tidak berubah.

## Rencana test
| AC | File | Nama test (usulan) |
|---|---|---|
| 1 | `tests/Feature/Database/Migrations/MarkExistingUsersEmailVerifiedTest.php` | marks unverified users and keeps existing dates |
| 2–4 | `tests/Feature/Auth/MultiFactorAuthenticationTest.php` (4–5 test) | stores secret encrypted; logs in with or without challenge; rejects wrong code; recovery code works once; profile shows app authentication |
| 5 | `tests/Feature/Filament/MultiFactorWarningTest.php` | warns super-admin and bendahara without mfa; hides after enabling; never warns other roles |
| 6–7 | `tests/Feature/Actions/Core/ResetUserMultiFactorTest.php` | resets and logs without secret; rejects non super-admin, self, user without mfa |
| 8 | `tests/Feature/Console/ResetMultiFactorCommandTest.php` | resets by email from cli; fails for unknown email or user without mfa |
| 9, 11, 12 (UI) | `tests/Feature/Filament/Resources/Users/UserSecurityActionsTest.php` | reset action visibility and confirmation; email change resets verification; mark verified action |
| 10, 13 | `tests/Feature/Auth/EmailVerificationTest.php` | redirects unverified users on panel and custom routes; profile email change stays verified |
| 12 (Action) | `tests/Feature/Actions/Core/MarkUserEmailVerifiedTest.php` | marks and logs; rejects non super-admin and verified user |
| 14 | `tests/Feature/Actions/Core/CreateSuperAdminTest.php` (tambah) | verifies existing unverified account on promotion |

Regresi wajib hijau: `UserResourceTest.php`, `PanelAccessTest.php`, `ModuleComplianceTest.php`, `CreateSuperAdminTest.php`, `InstallCommandTest.php`, test route kuitansi/unduhan yang ada. Factory boleh diberi state `withAppAuthentication()` bila red-tester butuh.

## Urutan implementasi
Paket 1 — data + model + Action + CLI (test: AC-1, 2, 6, 7, 8, 12 Action, 14):
1. Migration skema kolom MFA; migration data verifikasi email; `docs/erd.md`.
2. `User`: kontrak, trait, `shouldBeWarnedToEnableMultiFactor`, hook `updating`.
3. `ResetUserMultiFactor`, `MarkUserEmailVerified`, `ResetMultiFactorCommand`, perubahan `CreateSuperAdmin`, kunci `lang/id`.

Paket 2 — panel + UI (test: AC-3–5, 9–13, plus regresi):
4. `AdminPanelProvider`: MFA, email verification, email change verification, middleware `verified` di `authenticatedRoutes`, render hook.
5. View banner `filament/mfa-warning.blade.php`.
6. Header actions `EditUser`, helper dan field email di `UserForm`.
7. Pint, checklist design-guide §0, cek visual banner di 360px dan desktop.

File yang diubah: `database/migrations/2026_10_28_000000_add_app_authentication_to_users_table.php` (baru), `database/migrations/2026_10_28_000001_mark_existing_users_email_verified.php` (baru), `app/Models/User.php`, `app/Actions/Core/ResetUserMultiFactor.php` (baru), `app/Actions/Core/MarkUserEmailVerified.php` (baru), `app/Console/Commands/ResetMultiFactorCommand.php` (baru), `app/Actions/Core/CreateSuperAdmin.php`, `app/Providers/Filament/AdminPanelProvider.php`, `resources/views/filament/mfa-warning.blade.php` (baru), `app/Filament/Resources/Users/Pages/EditUser.php`, `app/Filament/Resources/Users/Schemas/UserForm.php`, `lang/id/pondok.php`, `docs/erd.md`, `database/factories/UserFactory.php` (opsional), `feature_list.json`.

## Risiko & pertanyaan terbuka
- Email verifikasi dan ganti email dikirim saat itu juga (tidak lewat queue). Bila SMTP belum diatur atau gagal, pengguna baru tertahan di halaman prompt. Jalan keluarnya aksi super-admin "Tandai email terverifikasi", atau `pondok:install` untuk akun super-admin. Super-admin tidak bisa mengubah email dirinya lewat `UserResource`, sehingga tidak bisa mengunci diri sendiri.
- Kode TOTP memakai cache (`CACHE_STORE=database`) untuk mencegah pemakaian ulang; aman di cPanel.

## Riwayat revisi
- 22 Okt 2026: draf awal.
- 22 Okt 2026: revisi Review #1 (lihat di bawah).

### Review #1 (plan-reviewer: CHANGES_REQUESTED, 1 major, 7 minor)
| # | Tingkat | Temuan | Penanganan |
|---|---|---|---|
| 1 | major | Super-admin tunggal tanpa HP/kode pemulihan tidak bisa dipulihkan | `pondok:reset-mfa {email}` + `ResetUserMultiFactor` menerima `?User $actor` (null = CLI, causer kosong, `via = cli`); UI tetap wajib super-admin dan bukan diri sendiri. AC-8 |
| 2 | minor | Teks konfirmasi reset kurang tepat | Diganti teks usulan reviewer (bagian UI) |
| 3 | minor | AC banner harus lewat HTTP | AC-5 memakai `get('/admin')` + `assertSee`/`assertDontSee` |
| 4 | minor | Route `authenticatedRoutes` tanpa verifikasi email | Dibungkus `$panel->getEmailVerifiedMiddleware()`; assertion di AC-10 |
| 5 | minor | Helper `hasAppAuthentication()` duplikat | Dibuang; pakai `filled($user->getAppAuthenticationSecret())` |
| 6 | minor | Helper text form + klaim risiko SMTP | Helper email saat create di `UserForm`; teks risiko SMTP dirapikan |
| 7 | minor | Hook pengosongan `email_verified_at` | Dipertahankan di model; AC-13 memastikan alur ganti email Filament tetap terverifikasi |
| 8 | minor | AC integrasi vendor terlalu rinci | Dipadatkan menjadi AC-2–4, 4–5 test di satu file |
