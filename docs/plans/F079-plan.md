# F079 portal-wali-blade-livewire — Portal wali Blade + Livewire (ganti panel Filament /wali)

Issue: #76
Status rencana: APPROVED (orkestrator, setelah revisi Review #1)

## Ringkasan & tujuan
Panel Filament `wali` dari F022 diganti halaman Blade + Livewire di `/wali` dengan tema portal publik (`<x-layouts::public>`, komponen `x-ui.*`, token F070/F071). Auth memakai bawaan Laravel (guard `web`, Password broker, MustVerifyEmail, RateLimiter). Aturan data F022 (K5 `User::portal*()`, K6 field yang tampil) tidak berubah; lihat `docs/plans/F022-plan.md`.

## Keputusan
- **K1 Halaman.** Form (masuk, lupa password, atur ulang password) = Livewire full-page `#[Layout('layouts.public')]` seperti `app/Livewire/Psb`. Halaman baca (beranda, santri, tagihan, prompt verifikasi) = controller + Blade. Paginasi tagihan = `paginate(10)` biasa + `portal.partials.pagination`.
- **K2 Route.** Grup `prefix('wali')->name('portal-wali.')` dengan middleware `web` + `EnsureModuleIsEnabled:portal-wali,404` + `AddPrivacyHeaders` (no-store, noindex, no-referrer; melindungi token di URL reset):
  - tamu (`guest`): `GET masuk` `login`, `GET lupa-password` `password.request`, `GET atur-password/{token}` `password.reset`.
  - `auth` + `auth.session` (`AuthenticateSession`: sesi lain gugur setelah password berubah) + `EnsurePortalWaliUser`: `GET email/verifikasi` `verification.notice`, `GET email/verifikasi/{id}/{hash}` `verification.verify` (`signed`, `throttle:6,1`, `EmailVerificationRequest`), `POST email/verifikasi` `verification.send` (`throttle:6,1`), `POST keluar` `logout`.
  - di atas + `verified:portal-wali.verification.notice`: `GET /` `home`, `GET santri/{student}` `students.show`, `GET tagihan/{invoice}` `invoices.show` (+ `EnsureModuleIsEnabled:keuangan,404`), `GET dokumen/{document}/unduh` `documents.download` (+ `dokumen,404`). Semua `whereNumber`.
  - `bootstrap/app.php`: `redirectGuestsTo(fn () => route('portal-wali.login'))`, `redirectUsersTo(fn () => route('portal-wali.home'))` (closure: route belum terdaftar saat bootstrap). Hanya untuk middleware `auth`/`guest` Laravel; Filament memakai middleware sendiri. Tanpa `abort` di closure.
  - **Urutan middleware:** prioritas `EnsureModuleIsEnabled` dipindah dari sebelum `SubstituteBindings` ke sebelum `Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests` (`prependToPriorityList(before: AuthenticatesRequests::class, …)`; `prependPriority` dikunci per kelas, jadi panggilan lama diganti, dan karena `AuthenticatesRequests` ada di atas `SubstituteBindings` di daftar Kernel, jaminan lama tetap). Akibatnya tamu di route portal saat modul mati mendapat 404, bukan redirect login. Dampak ke admin: route kustom admin ber-saklar kini menjawab 403 modul sebelum redirect login untuk tamu; middleware panel Filament (Authenticate di `authMiddleware`) tidak memuat saklar, jadi halaman panel tidak berubah. Komentar di `bootstrap/app.php` diperbarui.
- **K3 Pintu masuk.** Middleware baru `EnsurePortalWaliUser`: user `is_active` dan berperan `wali`, selain itu 403 dengan pesan `pondok.portal_wali.errors.not_wali` ("Akun ini bukan akun wali. Staf pondok masuk lewat halaman admin."), dicek tiap request termasuk dinonaktifkan di tengah sesi. `User::canAccessPanel` kembali hanya aturan admin (`is_active && can('view-dashboard')`).
- **K4 Login** (`Livewire\PortalWali\Login`): `Auth::attemptWhen(['email', 'password'], fn (User $u) => $u->is_active && $u->hasRole('wali') && ! $u->can('view-dashboard'))` (bawaan Laravel; tidak login bila callback false). Staf (termasuk staf yang juga wali) **ditolak** di sini dengan pesan umum `auth.failed`, karena guard `web` dipakai bersama panel admin: login portal tanpa MFA akan membuka `/admin` dan melewati MFA F055. Staf+wali masuk lewat `/admin` lalu membuka `/wali` (tetap boleh, K3). Halaman login memuat teks "Staf pondok masuk lewat halaman admin." Rate limit pola Laravel: kunci `Str::transliterate(lower(email)|ip)`, 5 gagal → `auth.throttle` (detik), `RateLimiter::clear` saat sukses. Sukses: `session()->regenerate()`, redirect `intended(home)`. Tanpa "ingat saya".
- **K5 Logout:** `Auth::guard('web')->logout()`, `session()->invalidate()`, `regenerateToken()`, redirect login. Karena sesi bersama, staf+wali ikut keluar dari admin.
- **K6 Lupa/atur ulang password.** `ForgotPassword`: `Password::sendResetLink(['email'], callback)`; callback hanya memanggil `$user->sendPasswordResetNotification($token)` bila `is_active` dan berperan `wali`. Pesan selalu sama (`passwords.sent`) apa pun hasil broker (tanpa membocorkan akun; `RESET_THROTTLED` juga). RateLimiter 5/menit per IP. `ResetPassword`: `Password::reset([...], fn)` → `forceFill(password, remember_token baru)->save()` + `event(new PasswordReset)`; listener `MarkEmailVerifiedOnPasswordReset` (tetap) menandai terverifikasi. Aturan password `Password::defaults()` + `confirmed`. Tidak login otomatis.
- **K7 URL notifikasi bawaan.** Di `AppServiceProvider`: `ResetPassword::createUrlUsing` → `route('portal-wali.password.reset', [token, email])` dan `VerifyEmail::createUrlUsing` → `URL::temporarySignedRoute('portal-wali.verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), [id, sha1(email)])`. Aman untuk admin: `Filament\Auth\Notifications\ResetPassword`/`VerifyEmail` meng-override `resetUrl()`/`verificationUrl()` dengan `$url` panel, jadi callback hanya mengenai notifikasi dasar Laravel yang dipakai portal wali. Tanpa token/kripto buatan.
- **K8 Buat akun (F022 K2) tetap.** `CreateGuardianPortalAccount::sendPasswordSetupLink` menjadi `Password::createToken($user)` + `$user->sendPasswordResetNotification($token)` (tanpa `Filament`). Notifikasi reset/verifikasi dasar Laravel dikirim **sinkron** (tidak `ShouldQueue`): queue `database` hanya diproses cron per menit sehingga tautan tertunda; risiko timing (respons sedikit lebih lambat bila akun ada) diterima, karena pesan layar tetap sama dan broker throttle per user (YAGNI).
- **K9 Ringkasan santri** (`withPortalSummary` dari trait yang dihapus) pindah ke `User::portalStudentsWithSummary(): Builder` agar beranda dan rincian santri memakai satu query (DRY, satu pintu K5).
- **K10 MFA wali tidak dibangun** (MFA Filament terikat panel). Wali yang sempat mengaktifkan MFA di panel F022 kehilangan langkah MFA; secret dibiarkan (tanpa migrasi). Usulan issue terpisah.
- **K11 Progres hafalan (F023) tidak dimasukkan**: di luar issue #76. Usulan issue terpisah (ringkas `student_juz_progress` di rincian santri saat Tahfidz aktif).

## Ruang lingkup
- Termasuk: route + middleware K2/K3, 3 komponen Livewire, 4 controller, view `portal-wali/*`, penyesuaian Action buat akun, `lang/id`, hapus panel wali, kembalikan design-guide, adaptasi test F022.
- Tidak termasuk: MFA wali, tahfidz, registrasi mandiri, "ingat saya", ganti email/profil wali (F022 profil Filament hilang; wali minta admin), tautan "Masuk wali" di header portal (nav tetap ke admin), dark mode.

## File
- **Hapus:** `app/Providers/Filament/WaliPanelProvider.php` (+ entri `bootstrap/providers.php`), `app/Filament/Wali/` (3 page), `app/Filament/Concerns/BelongsToPortalWali.php`, `resources/views/filament/wali/**`. Kunci `lang` khusus panel yang tak terpakai dibersihkan; `lang/vendor/filament-panels/id/layout.php` dipertahankan (admin).
- **Ubah:** `app/Models/User.php`, `app/Actions/PortalWali/CreateGuardianPortalAccount.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `routes/web.php`, `lang/id/pondok.php` (`portal_wali.*`), `docs/design-guide.md`, `tests/Pest.php` (helper `waliBoot`, `waliText` default `/wali` tetap, path/route baru; `Filament::setCurrentPanel('wali')` dibuang), `tests/Feature/ModuleComplianceTest.php`, `tests/Feature/PortalWali/*` (URL lama `/wali/login` → `/wali/masuk`, `/wali/documents/{id}/download` → `/wali/dokumen/{id}/unduh` di `PortalWaliModuleTest`, `WaliPagesTest`, `DownloadStudentDocumentTest`, `WaliVerifyTest`; `getPanel('wali')`/`canAccess` dibuang), `tests/Feature/Actions/PortalWali/*`, `app/Http/Controllers/PortalWali/DownloadStudentDocument.php` (hanya docblock).
- **Buat:** `app/Http/Middleware/EnsurePortalWaliUser.php`; `app/Livewire/PortalWali/{Login,ForgotPassword,ResetPassword}.php` + `resources/views/livewire/portal-wali/*.blade.php`; `app/Http/Controllers/PortalWali/{ShowHome,ShowStudent,ShowInvoice,EmailVerificationController,Logout}.php`; `resources/views/portal-wali/{home,student,invoice,verify-email}.blade.php` + `partials/{account-bar,student-summary}.blade.php`.

## Model data
Tanpa migration. `users.app_authentication_*` tetap (F055 admin).

## UI
Design-guide §6 (portal, mobile-first, `max-w-2xl`, tombol `w-full sm:w-auto`, target 44px), §4 badge (`Invoice::statusColor/statusLabel`, status dokumen), §5 (`Rp 1.250.000`, `15 Okt 2026`), §7 empty state, §9 sapaan "Anda". `account-bar` di atas konten: nama wali, tautan Beranda, tombol Keluar (form POST). Komponen `x-ui.card/badge/button/field/alert/empty-state`; tanpa `dark:`. Halaman wali `noindex`. Kembalikan design-guide: §0 hapus butir panel wali (ganti: "Portal wali: data hanya lewat `User::portal*()`"), §2 tabel dan paragraf "Portal dan panel" tanpa `WaliPanelProvider`, baris dark mode ke teks sebelum `568c4ad`, §6 hapus subbagian "Portal wali (panel Filament)" dan judul kembali "Portal publik, portal wali, PSB (Blade + Livewire)" dengan `max-w-2xl (portal wali, form PSB)` dan tombol "portal wali/PSB" (`git show 568c4ad`).

## Permission & cakupan
Tanpa permission baru; `wali` tetap `[]`. Cakupan `unit_user` tidak berlaku untuk data wali (K5 F022, `withoutUnitScope`). Saklar: `portal-wali` (semua route, termasuk login; Livewire `boot()` `abort_unless(isEnabled, 404)` karena `/livewire/update` tanpa middleware route), `keuangan` (bagian tagihan + route tagihan), `dokumen` (bagian dokumen + route unduh), `asrama` (kamar).

## Kriteria penerimaan (bisa dites)
- AC-1 Tanpa panel wali: `Filament::getPanels()` tidak punya `wali`; tidak ada route `filament.wali.*`; `bootstrap/providers.php` tanpa `WaliPanelProvider`; `ModuleComplianceTest` memeriksa setiap route `portal-wali.*` memuat `EnsureModuleIsEnabled:portal-wali,404` (unduh juga `dokumen,404`, tagihan `keuangan,404`). `/wali` dirender dengan layout portal (nama sekolah + teks lewati-ke-konten portal).
- AC-2 Modul mati: setiap route `portal-wali.*` (masuk, lupa/atur password, verifikasi, beranda, santri, tagihan, unduh) → 404 untuk wali login, dan **tamu** (dataset per route: `/wali/masuk`, `/wali`, santri, tagihan, unduh) → 404, bukan redirect login; komponen Livewire yang sudah dimuat lalu modul dimatikan → aksi `authenticate`/`send`/`resetPassword` 404; aksi "Buat akun portal" tersembunyi dan Action menolak.
- AC-3 Akses: wali aktif terverifikasi → `/wali` 200; wali → `/admin` 403; tamu → redirect `portal-wali.login`; dataset 9 peran bawaan non-wali login → `/wali` 403; wali nonaktif (juga di tengah sesi) → 403; staf non-wali yang sudah login membuka `/wali/masuk` → diarahkan ke beranda → 403 berisi teks "masuk lewat halaman admin"; bendahara+wali yang `actingAs` → `/admin` dan `/wali` 200, beranda hanya santri tautannya.
- AC-4 Login: wali aktif dengan password benar → terautentikasi, redirect beranda, ID sesi berubah (regenerasi). Password salah, email tak dikenal, staf non-wali berpassword benar, staf+wali, wali nonaktif → tetap tamu, error `email` = `__('auth.failed')` (teks identik semua kasus).
- AC-5 Rate limit login: 5 gagal untuk email+IP sama → percobaan ke-6 (walau password benar) ditolak dengan `auth.throttle` dan tetap tamu; email lain dari IP sama tidak terkunci; login sukses menghapus hitungan.
- AC-6 Logout: `POST /wali/keluar` → tamu, sesi lama tidak lagi terautentikasi, token CSRF baru, redirect login; `GET /wali/keluar` → 405.
- AC-7 Lupa password: email wali aktif → satu `Illuminate\Auth\Notifications\ResetPassword` dengan URL mail berawalan `route('portal-wali.password.reset', token)`; email staf non-wali, wali nonaktif, atau tak dikenal → tanpa notifikasi, pesan sukses sama; permintaan ke-6 per IP per menit → pesan throttle, tanpa notifikasi.
- AC-8 Atur ulang password: token valid → password berubah, `email_verified_at` terisi (yang sudah terverifikasi tidak berubah tanggalnya), redirect login dengan status; sesi lain user itu (dibuat sebelum reset, dengan `password_hash_web` lama) tidak lagi terautentikasi di `/wali`; halaman atur password ber-`Cache-Control` `no-store`; token salah/kedaluwarsa → error, password dan `email_verified_at` tetap; konfirmasi tidak cocok → error validasi.
- AC-9 Admin tidak terdampak: reset password lewat halaman Filament admin → `Filament\Auth\Notifications\ResetPassword` dengan URL `/admin/...` (bukan `/wali`); prompt verifikasi email admin mengirim URL `/admin/...`; login `/admin/login` staf tetap berjalan.
- AC-10 Verifikasi email: wali belum terverifikasi → beranda, santri, unduh redirect ke `portal-wali.verification.notice` (200, tombol kirim ulang); `POST` kirim → `VerifyEmail` dengan URL bertanda tangan ke `portal-wali.verification.verify` yang `expires` = sekarang + `auth.verification.expire` menit (default 60); URL yang lewat masa berlaku → 403; ke-7 dalam semenit → 429; buka URL → terverifikasi, redirect beranda; hash salah, tanpa tanda tangan, atau id user lain → 403 dan tetap belum terverifikasi.
- AC-11 Beranda (F022 AC-4): dua santri bersaudara tampil, santri wali lain tidak; jenjang, rombel, sisa `Rp 1.250.000` (hilang saat Keuangan mati), kamar (hilang saat Asrama mati); wali tanpa guardian/santri → empty state 200.
- AC-12 Batas data (F022 AC-5/6/7 + VERIFY): santri, tagihan, unduh milik wali lain atau id tak ada → 404 seragam; id non-angka → 404; santri diarsipkan atau tautan dilepas → hilang dari beranda dan 404; wali dengan `unit_user` jenjang lain tetap melihat santri, rombel, kamar, tagihan, pembayaran, dokumennya; dokumen milik guardian ber-id sama tidak bocor.
- AC-13 Rincian santri & tagihan (F022 AC-8/9): tagihan `batal` tidak tampil dan 404; total/sisa/badge status benar; >10 tagihan berhalaman; NIK/KK/NISN/alamat tidak ada di HTML; Keuangan mati → bagian tagihan hilang, halaman 200; Dokumen mati → bagian dokumen hilang. Rincian tagihan: item `base_amount`/`discount_amount`/`amount`, pembayaran `diterima` saja, tanggal `15 Okt 2026`.
- AC-14 Unduh dokumen (F022 AC-10): hanya dokumen current non-rahasia santri sendiri; unduh 200 attachment, `Cache-Control` memuat `no-store` (assert no-store hanya di sini dan AC-8), dari disk `private`; versi lama, jenis rahasia, milik guardian, file hilang → 404; tamu → redirect login.
- AC-15 Buat akun (F022 AC-11..13): tetap lulus, dengan satu `Illuminate\Auth\Notifications\ResetPassword` (bukan kelas Filament) yang URL-nya berawalan `/wali/atur-password/`; membuka URL itu → 200 form atur password.

## Rencana test
- `tests/Feature/PortalWali/PortalWaliModuleTest.php` — AC-1 (sebagian), AC-2.
- `tests/Feature/ModuleComplianceTest.php` — ganti test panel wali menjadi `AC-9: portal wali has no Filament panel and every portal-wali route is module guarded` (AC-1).
- `tests/Feature/PortalWali/WaliAccessTest.php` (ganti nama dari `WaliPanelAccessTest`) — AC-3, AC-12 (scope jenjang).
- `tests/Feature/PortalWali/WaliAuthTest.php` (baru) — AC-4, AC-5, AC-6, AC-7, AC-8, AC-9, AC-10.
- `tests/Feature/PortalWali/WaliPagesTest.php` — AC-11, AC-12, AC-13.
- `tests/Feature/PortalWali/DownloadStudentDocumentTest.php` — AC-14.
- `tests/Feature/PortalWali/WaliVerifyTest.php` — test Livewire-tampering panel dihapus (halaman baca bukan Livewire lagi); sisanya diadaptasi ke route baru (AC-2, AC-3, AC-12).
- `tests/Feature/Actions/PortalWali/CreateGuardianPortalAccountTest.php` — AC-15, AC-8 (bagian listener).
- Regresi: `tests/Feature/Filament/PanelAccessTest.php`, F055 (`tests/Feature/**/MultiFactor*`, verifikasi email admin), `DocumentDownloadTest`, `ModulesDisabledSmokeTest`.

## Urutan implementasi
Paket 1 — fondasi & auth (hijau: WaliAuthTest, PortalWaliModuleTest AC-2 bagian login, CreateGuardianPortalAccountTest, ModuleComplianceTest)
1. Hapus panel wali, trait, view; `canAccessPanel` hanya admin; `portalStudentsWithSummary()`.
2. Middleware `EnsurePortalWaliUser`; `redirectGuestsTo/UsersTo`; callback URL K7; grup route.
3. Livewire `Login`, `ForgotPassword`, `ResetPassword` + view; `Logout`; `EmailVerificationController`; lang.
4. `CreateGuardianPortalAccount` memakai notifikasi Laravel.

Paket 2 — halaman baca (hijau: WaliAccessTest, WaliPagesTest, DownloadStudentDocumentTest, WaliVerifyTest, PortalWaliModuleTest)
5. `ShowHome`, `ShowStudent`, `ShowInvoice` + view dan partial; route unduh baru.
6. design-guide §0/§2/§6; Pint; cek 360px, 1024px, desktop.

## Risiko & pertanyaan terbuka
- Sesi bersama admin/wali (guard `web`): logout portal juga mengeluarkan staf+wali dari admin; diterima orkestrator (KISS, tanpa guard kedua).
- Staf+wali tidak bisa login di `/wali/masuk` (K4): diterima orkestrator.
- Perubahan prioritas middleware (K2) menyentuh semua route ber-saklar; regresi `ModuleComplianceTest`, `ModulesDisabledSmokeTest`, `ModuleSwitchVerifyTest`, `PanelAccessTest` wajib hijau.
- Wali kehilangan profil/ganti email/MFA panel F022 (K10), diterima orkestrator. Usulan (dilaporkan orkestrator ke user, tanpa issue dari sesi ini): MFA wali, progres hafalan di portal wali (K11).
- `createUrlUsing` bersifat global; bila kelak ada notifikasi reset/verifikasi Laravel untuk pengguna lain, URL-nya ke portal wali. Dicatat di docblock AppServiceProvider.

## Riwayat revisi
- DRAFT 1 (22 Okt 2026): rencana awal.
- Revisi 1 (22 Okt 2026): menjawab Review #1; keputusan staf+wali ditolak di login portal, logout bersama, dan wali tanpa profil/MFA diterima orkestrator.

### Review #1 (plan-reviewer: CHANGES_REQUESTED, 4 major, 4 minor)
| # | Tingkat | Temuan | Penanganan |
|---|---|---|---|
| 1 | major | `redirectGuestsTo/UsersTo(route(...))` gagal saat bootstrap | Closure `fn () => route(...)` (K2) |
| 2 | major | `EnsureModuleIsEnabled` jalan setelah `auth`, tamu dapat redirect bukan 404 | Prioritas sebelum `AuthenticatesRequests` (K2), dampak admin dicatat; AC-2 dataset tamu per route |
| 3 | major | Sesi lain tetap hidup setelah reset password | `auth.session` di grup auth portal (K2); AC-8 |
| 4 | major | Masa berlaku URL verifikasi tidak jelas | `config('auth.verification.expire', 60)` (K7); AC-10 assert `expires` dan kedaluwarsa → 403 |
| 5 | minor | Notifikasi sinkron vs queue | Sinkron, risiko timing diterima (K8) |
| 6 | minor | Staf non-wali login membuka `/wali/masuk` | 403 dengan teks masuk lewat admin (K3, AC-3) |
| 7 | minor | Cakupan assert `no-store` | Hanya halaman atur password (AC-8) dan unduh (AC-14) |
| 8 | minor | Daftar file test belum lengkap | `tests/Pest.php` dan URL lama per file dirinci (File) |
