# F081 portal-wali-email-akun-baru — Email undangan akun portal wali

Issue: #80
Status rencana: APPROVED setelah revisi #1

Jalur penuh (menyentuh alur auth/token password dan migration). Keputusan user 22 Okt 2026: **opsi 2**.

## Ringkasan & tujuan

"Buat akun portal" (`CreateGuardianPortalAccount`, F022/F079) kini mengirim notifikasi reset password bawaan Laravel: teksnya seperti permintaan reset ("surel", "kata sandi") dan tautannya kedaluwarsa 60 menit. Ganti dengan notifikasi undangan khusus berteks portal (email/password) dan token dari broker password terpisah yang berlaku **72 jam**. Alur "Lupa password" tetap 60 menit.

## Ruang lingkup

- Termasuk: broker `guardian_invitations` + tabel tokennya sendiri; notifikasi `GuardianPortalInvitation`; halaman atur password portal wali memilih broker dari penanda undangan; token kedua broker dihapus setelah password berhasil diatur; satu pesan galat portal `pondok.portal_wali.reset_invalid` untuk semua kegagalan atur password (review #1); teks `lang/id/pondok.php` kunci `portal_wali.invitation.*`; `docs/erd.md`.
- Tidak termasuk (eksplisit):
  - Aksi admin "Kirim ulang undangan". Alasan YAGNI: issue tidak memintanya, dan email undangan menyuruh wali memakai "Lupa password" bila tautan kedaluwarsa (alur itu sudah melayani wali aktif yang belum terverifikasi). Bila dibutuhkan, usulkan issue baru.
  - `lang/id/passwords.php` tidak diubah, dan notifikasi "Lupa password" tetap notifikasi bawaan.
  - Antrean (queue) untuk notifikasi: tetap dikirim sinkron seperti sekarang.

## Model data

- Migration baru `create_guardian_invitation_tokens_table`: `guardian_invitation_tokens` (`email` string primary, `token` string, `created_at` timestamp nullable), bentuk sama dengan `password_reset_tokens`. Bukan data keuangan, tanpa soft delete, tanpa FK. `down()` = drop table.
- **Alasan tabel terpisah (bukan tabel bersama):** `DatabaseTokenRepository` menyimpan satu baris per email dan masa berlaku dicek oleh broker yang memvalidasi. Bila tabel dipakai bersama, token "Lupa password" yang dibawa ke tautan undangan divalidasi dengan expire 72 jam (celah), dan membuat token di satu broker menimpa token broker lain. Tabel terpisah membuat setiap broker hanya mengenali tokennya sendiri.
- `config/auth.php` `passwords.guardian_invitations`: `provider` users, `table` `guardian_invitation_tokens`, `expire` 4320 (72 jam), **tanpa `throttle`** (throttle hanya dipakai `sendResetLink`; undangan memakai `createToken` dan dibuat sekali per wali saat akun dibuat).
- `docs/erd.md` §tabel bawaan Laravel (±baris 980): tambahkan `guardian_invitation_tokens` dengan satu kalimat fungsi dan masa berlaku.

## Action class

- `app/Actions/PortalWali/CreateGuardianPortalAccount` (ubah `sendPasswordSetupLink`): `$token = Password::broker('guardian_invitations')->createToken($user)` lalu `$user->notify(new GuardianPortalInvitation($token))`. Aturan, transaksi, dan activity log yang ada tidak berubah. Tidak ada Action baru: KISS, satu pemakai. Docblock diperbarui: email dikirim di luar transaksi seperti F022, jadi kegagalan kirim email setelah akun dibuat tidak membatalkan akun (wali memakai "Lupa password").
- Nama broker dipakai di dua tempat (Action dan halaman reset): simpan sebagai konstanta `GuardianPortalInvitation::BROKER = 'guardian_invitations'` agar tidak tersebar sebagai string.

## Notifikasi

`app/Notifications/GuardianPortalInvitation` (folder baru; Service/Notification bukan logika bisnis): `via` = `mail`, tidak `ShouldQueue`. `toMail`:
- Subjek `portal_wali.invitation.subject` ("Akun portal wali Anda sudah dibuat" + nama aplikasi bila pola mail lain memakainya).
- Sapaan dengan nama user, paragraf pembuka (akun portal wali dibuat oleh admin pondok, atur password untuk mulai masuk).
- Tombol `portal_wali.invitation.action` ("Atur password") ke `route('portal-wali.password.reset', ['token' => $token, 'email' => $email, 'undangan' => 1])`.
- Kalimat masa berlaku dengan `:hours` dihitung dari `config('auth.passwords.guardian_invitations.expire') / 60` (bukan angka tetap di teks).
- Kalimat "Bila tautan sudah kedaluwarsa, minta tautan baru lewat Lupa password" dengan URL `route('portal-wali.password.request')`.
- Kalimat "Abaikan email ini bila Anda tidak merasa sebagai wali santri di pondok kami".
- Semua teks dari `lang/id/pondok.php` `portal_wali.invitation.*`; istilah **email** dan **password**, sapaan "Anda" (design-guide §9), tanpa emoji/tanda seru beruntun. Teks bawaan `MailMessage` (salam, "Jika Anda mengalami kesulitan mengklik…") berasal dari `lang/id.json`; baris itu berisi "URL"/"browser", tidak memuat "surel"/"kata sandi" — red-tester memverifikasi di AC-3.

## UI (Livewire portal wali)

`app/Livewire/PortalWali/ResetPassword`:
- `#[Locked] public bool $invitation`, diisi di `mount` dari `request()->boolean('undangan')` (hanya dibaca saat mount; di test dipasang lewat `Livewire::withQueryParams(['undangan' => 1])` sebelum `Livewire::test()`, tanpa parameter mount tambahan). Aman meski penanda ada di URL: berpindah broker tidak pernah memperluas akses, karena token hanya dikenali tabel brokernya sendiri dengan masa berlakunya sendiri.
- `resetPassword()`: `Password::broker($this->invitation ? GuardianPortalInvitation::BROKER : null)->reset(...)`, callback sama seperti sekarang (password, remember_token baru, event `PasswordReset` → email terverifikasi).
- Setelah `PASSWORD_RESET`: hapus token user di broker lainnya (`Password::broker(<lain>)->deleteToken($user)`), agar undangan lama atau tautan lupa password yang masih tertunda tidak bisa dipakai lagi. Perlu objek user: ambil dari callback (variabel lokal) — tanpa query tambahan.
- Status selain `PASSWORD_RESET` (`INVALID_TOKEN`, `INVALID_USER`, dan lainnya) dipetakan ke satu galat `email` = `__('pondok.portal_wali.reset_invalid')` = "Tautan tidak sah atau sudah kedaluwarsa. Minta tautan baru lewat Lupa password." Menggantikan `__($status)` (teks vendor "kata sandi") dan menghapus pembeda "email tidak ada" vs "token salah".
- Tampilan Blade tidak berubah. Panel admin: tidak ada perubahan (helper teks "Tautan untuk mengatur password dikirim ke email ini." tetap benar).

## Permission & cakupan

Tidak ada permission baru. Aksi "Buat akun portal" tetap dicek `canEdit` + modul `PortalWali` aktif (F022). Halaman atur password tetap di bawah middleware `EnsureModuleIsEnabled:portal-wali` dan `abort_unless(Module::PortalWali->isEnabled(), 404)` di aksi Livewire. `ForgotPassword` tidak berubah (broker default).

## Kriteria penerimaan (bisa dites)

- **AC-1** Admin-sekolah memanggil aksi `createPortalAccount` → tepat satu `GuardianPortalInvitation` terkirim ke user baru, **tidak ada** `Illuminate\Auth\Notifications\ResetPassword`; satu baris di `guardian_invitation_tokens` untuk email itu, tidak ada baris di `password_reset_tokens`.
- **AC-2** URL tombol di mail mengarah ke `/wali/atur-password/{token}` dengan `email` dan `undangan=1`; GET URL itu → 200.
- **AC-3** Isi mail (subjek, baris, teks tombol, dirender `toMail(...)->render()` atau gabungan baris) memuat "password", "72 jam", dan URL `/wali/lupa-password`; tidak memuat "surel", "kata sandi", atau "Atur Ulang Kata Sandi" (case-insensitive). Subjek sama dengan `__('pondok.portal_wali.invitation.subject')`.
- **AC-4** Token undangan + `undangan=1`: 71 jam 59 menit setelah dibuat (travel) atur password berhasil → redirect `portal-wali.login`, password berubah, `email_verified_at` terisi.
- **AC-5** Token undangan + `undangan=1` setelah 72 jam lewat 1 menit → galat pada `email`, password dan `email_verified_at` tidak berubah.
- **AC-6** Token undangan **tanpa** penanda undangan (jalur default) → ditolak, tidak ada perubahan, walau masih di menit ke-1.
- **AC-7** Token "Lupa password" (`Password::createToken`, broker `users`) dibawa dengan `undangan=1` → ditolak (baik di menit ke-1 maupun setelah 61 menit); jalur default tanpa penanda tetap menerima token itu dalam 60 menit dan menolaknya setelah 61 menit (regresi F079).
- **AC-8** User punya token di kedua broker (dibuat dulu di test). Atur password berhasil lewat jalur undangan → baris user di `password_reset_tokens` terhapus. Sebaliknya (kedua token dibuat lagi), berhasil lewat jalur lupa password → baris di `guardian_invitation_tokens` terhapus dan token undangan lama ditolak.
- **AC-9** Wali dengan undangan tertunda meminta "Lupa password" → notifikasi `ResetPassword` bawaan terkirim (perilaku F079 tetap), dan token undangan tetap berlaku sampai dipakai atau kedaluwarsa (tabel tidak saling menimpa).
- **AC-10** Kegagalan pembuatan akun (email terpakai, email tidak valid, wali sudah tertaut, modul mati) → tidak ada notifikasi apa pun dan tidak ada baris di `guardian_invitation_tokens`.
- **AC-11** Modul Portal wali mati: GET tautan undangan → 404; aksi `resetPassword` dengan token undangan valid → 404, password tidak berubah.
- **AC-12** `config('auth.passwords.guardian_invitations')` = provider `users`, table `guardian_invitation_tokens`, expire 4320; `config('auth.passwords.users.expire')` tetap 60; `Schema::hasColumns('guardian_invitation_tokens', ['email','token','created_at'])`.
- **AC-13** Token salah, email tidak terdaftar, dan token undangan kedaluwarsa masing-masing menghasilkan galat `email` yang identik, sama dengan `__('pondok.portal_wali.reset_invalid')`, dan tidak memuat "kata sandi".

## Rencana test

| AC | File | Nama test (usulan) |
|---|---|---|
| 1, 2, 3, 10 | `tests/Feature/Actions/PortalWali/CreateGuardianPortalAccountTest.php`: test sukses lama (AC-11 F022: import `ResetPasswordNotification` baris 9, assert `ResetPasswordNotification`/`actionUrl` baris 40-47) **diganti** menjadi assert `GuardianPortalInvitation`, URL mengandung `/wali/atur-password/` dan `undangan=1`, GET URL → 200 | `sends a portal invitation instead of the Laravel reset notification`, `the invitation mail uses portal wording and the 72-hour validity`, `failed account creation sends nothing and stores no invitation token` |
| 4–9, 11 | `tests/Feature/PortalWali/GuardianInvitationTest.php` (baru) | `accepts an invitation token until 72 hours`, `rejects an expired invitation token`, `rejects an invitation token on the default reset link`, `rejects a forgot-password token on the invitation link`, `clears the other broker token after a successful reset`, `forgot password keeps a pending invitation`, `returns 404 when the module is off` |
| 12, 13 | `tests/Feature/PortalWali/GuardianInvitationTest.php` | `registers a separate invitation broker with its own table`, `shows one identical error for a wrong token, an unknown email and an expired invitation` |

Helper: tambahkan parameter opsional `bool $invitation = false` ke `waliReset()` di `tests/Pest.php` (pakai `Livewire::withQueryParams(['undangan' => 1])`). Test F079 yang ada (`WaliAuthTest` AC-8, `WaliAdversarialTest` token salah/token user lain) hanya mengassert `assertHasErrors(['email'])`, bukan teks lama, jadi tetap hijau tanpa diubah.

## Urutan implementasi

Paket 1 — data + broker + Action (hijau: `CreateGuardianPortalAccountTest.php`, AC-12):
1. Migration `guardian_invitation_tokens` + config broker `guardian_invitations`.
2. Notifikasi `GuardianPortalInvitation` + kunci `portal_wali.invitation.*` di `lang/id/pondok.php`.
3. `CreateGuardianPortalAccount` memakai broker undangan dan notifikasi baru; perbarui docblock.

Paket 2 — halaman atur password (hijau: `GuardianInvitationTest.php`, `WaliAuthTest.php`):
4. `ResetPassword`: properti `$invitation`, pemilihan broker, hapus token broker lain setelah sukses, galat tunggal `reset_invalid` (+ kunci di `lang/id/pondok.php`); perbarui docblock.
5. `docs/erd.md`; Pint; jalankan `tests/Feature/PortalWali` dan `tests/Feature/Actions/PortalWali`.

## Risiko & pertanyaan terbuka

- **Pertanyaan (user):** aksi admin "Kirim ulang undangan" tidak termasuk (lihat Ruang lingkup). Usulkan issue baru bila admin perlu.
- Risiko: `ResetPassword::createUrlUsing` (AppServiceProvider) tetap global untuk notifikasi bawaan; notifikasi undangan membangun URL sendiri, jadi tidak terpengaruh.
- Risiko: tautan undangan yang sudah terkirim sebelum rilis (token di `password_reset_tokens`, tanpa `undangan=1`) tetap jalan di jalur default sampai 60 menit; tidak perlu migrasi data.
- Instalasi yang sudah jalan wajib `php artisan migrate` (tabel baru); catat di catatan rilis.

## Riwayat revisi

- 22 Okt 2026: DRAFT pertama.
- 22 Okt 2026: revisi #1 atas Review #1 (di bawah).

### Review #1 (plan-reviewer: CHANGES_REQUESTED, 0 blocker, 1 major, 5 minor)

| # | Tingkat | Temuan | Tindak lanjut |
|---|---|---|---|
| 1 | major | Galat atur password masih teks vendor "kata sandi" dan membedakan email/token | Masuk lingkup: galat tunggal `portal_wali.reset_invalid`, AC-13; `passwords.php` tidak diubah; `WaliAdversarialTest` tidak perlu diubah |
| 2 | minor | Test sukses lama di `CreateGuardianPortalAccountTest` belum disebut eksplisit | Rencana test: baris 9 dan 40-47 diganti (undangan=1, GET 200) |
| 3 | minor | AC-8 "bila ada" lemah | AC-8 mewajibkan token broker lain dibuat dulu lalu diassert terhapus |
| 4 | minor | `throttle` broker undangan tidak terpakai | Dihapus dari config |
| 5 | minor | Cara test memasang penanda undangan | `Livewire::withQueryParams(['undangan' => 1])`, tanpa parameter mount |
| 6 | minor | Docblock kegagalan kirim email | Dicatat di bagian Action (di luar transaksi, seperti F022) |

Aksi "Kirim ulang undangan" tetap di luar lingkup (disetujui reviewer).
