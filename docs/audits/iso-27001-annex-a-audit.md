# Audit ISO/IEC 27001:2022 (Annex A) — Pondok

**Tanggal**: 17 Oktober 2026
**Auditor**: Claude Code (skill `implementing-iso-27001-information-security-management`)
**Repositori**: `pondok/pondok`
**Cakupan**: Audit keamanan teknis aplikasi Laravel 13 + Filament 5 terhadap kontrol Annex A ISO/IEC 27001:2022 (kategori A.5–A.8). Audit dilakukan dari kode, konfigurasi, dan dokumentasi di repo — bukan audit penuh sistem manajemen (ISMS klausul 4–10).

Dokumen ini sengaja ditulis **dua lapis**:

- **Bagian A** — ringkasan untuk orang awam (operator sekolah, reviewer non-teknis).
- **Bagian B** — detail teknis dengan bukti (path file + nomor baris) untuk developer.

---

# Bagian A — Ringkasan Bahasa Sederhana

## Apa yang diperiksa?

Saya memeriksa keamanan aplikasi Pondok dengan standar ISO 27001, khususnya bagian "Annex A" yang berisi daftar kontrol keamanan. Saya fokus ke hal yang bisa dicek langsung dari kode: cara login, pengaturan hak akses, perlindungan data santri dan keuangan, pencatatan aktivitas (log), serta pengamanan konfigurasi.

## Hasilnya: Bagus

**Tidak ada temuan kritis.** Fondasi keamanan Pondok tergolong matang untuk aplikasi yang dijalankan di shared hosting tanpa staf IT khusus. Banyak hal yang biasanya jadi "lubang" di aplikasi sekolah justru sudah diterapkan dan bahkan sudah diuji otomatis.

Yang sudah **baik** dan bisa dijadikan bukti:

- Login admin dibatasi 5 kali percobaan per menit dan dilindungi dari serangan tebak password.
- Hak akses diatur per peran (bendahara, admin PSB, kesantrian, dll.) — tiap peran hanya melihat dan mengubah yang menjadi tugasnya.
- Data dibatasi per jenjang (unit), jadi petugas satu jenjang tidak melihat data jenjang lain.
- File sensitif (dokumen santri, bukti transfer) disimpan di luar folder web dan hanya bisa diunduh lewat jalur yang benar.
- Nomor dokumen rahasia (KK/NIK) dienkripsi di database.
- Tidak ada celah umum seperti injeksi SQL, eksekusi perintah, atau celah upload file.
- Ada ratusan test otomatis yang memeriksa keamanan (anti-XSS, CSRF, batas akses, upload, privasi).

## Hal yang perlu diperbaiki

Meski bagus, ada beberapa hal yang **perlu diperkuat**, diurutkan dari yang paling penting:

1. **Cookie login belum dipaksa aman di produksi.** Saat ini cookie sesi bisa saja terkirim lewat jalur tidak terenkripsi (HTTP), padahal aplikasi diakses lewat HTTPS. Perbaikan: tambahkan satu baris pengaturan.
2. **Data sesi dan data pribadi sebagian besar tersimpan polos (tidak terenkripsi) di database.** Password sudah di-hash dan nomor dokumen sudah dienkripsi, tapi NISN, tanggal lahir, alamat, telepon, dan salinan surat masih polos.
3. **Belum ada verifikasi dua langkah (2FA)** untuk akun penting seperti bendahara dan admin.
4. **Belum ada fitur lupa password** di halaman admin.
5. **Belum ada aturan penghapusan/anonymisasi data** santri yang sudah lulus atau keluar — datanya disimpan selamanya.
6. **Belum ada backup database otomatis** dari sisi aplikasi.
7. **Belum ada peringatan otomatis (alert)** saat terjadi error — pencatatan log sudah bagus, tapi tidak ada yang memberi tahu bila ada masalah.
8. **HTTPS belum dipaksa dengan HSTS**, dan halaman admin belum diberi pengaman header sepenuhnya (CSP).

## Kesimpulan untuk orang awam

| Pertanyaan | Jawaban |
|---|---|
| Apakah ada celah kritis? | Tidak |
| Apakah aman ditunjukkan ke orang lain? | Ya, fondasinya bagus |
| Apakah harus segera ditambal? | Tidak darurat, tapi ada 8 penguatan yang disarankan |
| Bagian mana yang paling perlu diperhatikan? | Enkripsi data saat tersimpan & cookie aman |
| Apakah butuh staf IT untuk menutup sisanya? | Tidak — sebagian besar cukup ubah pengaturan/dokumentasi |

---

# Bagian B — Detail Teknis

## Metode

Audit dilakukan dengan menelusuri 4 area secara paralel: (1) autentikasi/sesi/kontrol akses, (2) proteksi data & kriptografi, (3) logging/monitoring/error handling, (4) konfigurasi & dependensi. Setiap temuan disertai bukti path + nomor baris.

**Stack terkonfirmasi**: PHP 8.3, Laravel 13 (`composer.json:12`), Filament 5 (`:11`), `spatie/laravel-permission` (`:15`), `bezhansalleh/filament-shield` (`:10`), `spatie/laravel-activitylog` (`:14`), Tailwind 4 + Vite 8 (`package.json`).

## Pemetaan Kontrol Annex A

Legenda status: ✅ Baik · 🟡 Sebagian · ❌ Lemah/kosong · ➖ Tidak dapat dinilai dari kode.

### A.5 — Kontrol Organisasi

| Kontrol | Status | Temuan & Bukti |
|---|---|---|
| **A.5.8** Security in project management | 🟡 Sebagian | Ada proses rilis/upgrade/backup (`docs/instalasi.md:128-140`), tapi tidak ada artefak formal manajemen keamanan proyek (threat model / SDLC policy). |
| **A.5.10** Acceptable use / informasi | 🟡 Sebagian | Pemisahan modul (`Setting::moduleEnabled`), scope unit (`app/Models/Scopes/UnitScope.php`), header privasi. Dokumen kebijakan penggunaan belum terlihat di repo. |
| **A.5.15** Access control | ✅ Baik | RBAC granular: 10 role (`database/seeders/RolePermissionSeeder.php:16-19`), permission per resource/aksi (`:151-245`), policy Shield (`app/Policies/*`), scoping per unit (`UnitScope.php:19-34`), gate panel (`app/Models/User.php:38-41`). |
| **A.5.16** Identity management | 🟡 Sebagian | Ada `is_active`, pembatasan hapus user, penugasan super-admin dibatasi (`UserForm.php:56-59`). Lemah: email verification tidak dipakai, tidak ada self-service ganti password. |
| **A.5.17** Authentication information | 🟡 Sebagian | Password di-hash bcrypt cost 12 (`User.php:33`, `.env:16`), `http_only=true` + `same_site=lax` (`config/session.php:185,202`). Lemah: password policy hanya `min:8` (`UserForm.php:37`), cookie sesi tidak `Secure` di template produksi (`session.php:172`), `SESSION_ENCRYPT=false` (`:50`). |
| **A.5.23** Cloud services (shared hosting) | ✅ Baik | Aplikasi di luar `public_html` (`docs/instalasi.md:27,30,47`), root `.htaccess` deny-all (`/.htaccess:9-15`), `pondok:doctor`, AutoSSL (`docs/instalasi.md:119`). |
| **A.5.25** Assessment & decision on events | 🟡 Sebagian | Exception di-log dan produksi menyembunyikan detail (`APP_DEBUG=false`). Kosong: tidak ada alur triage/eskalasi insiden, hanya halaman 404 custom (`resources/views/errors/404.blade.php`). |
| **A.5.31** Legal/contractual | 🟡 Sebagian | Ada consent pendaftaran PSB (wajib). Kontrak hosting/DPA tidak dapat dinilai dari kode. |
| **A.5.33** Protection of records | ✅ Baik | FK `restrictOnDelete` pada data keuangan (`payments`), snapshot surat (`IssuedLetter.php`), soft delete siswa/pendaftar. |

### A.6 — Kontrol Sumber Daya Manusia

| Kontrol | Status | Temuan & Bukti |
|---|---|---|
| **A.6.1–A.6.8** (Screening, awareness, dll.) | ➖ Tidak dinilai | Termasuk proses organisasi di luar kode; tidak ada artefak di repo. |

### A.7 — Kontrol Fisik

| Kontrol | Status | Temuan & Bukti |
|---|---|---|
| **A.7.1–A.7.14** (Physical security) | ➖ Tidak dinilai | Di luar cakupan audit kode. |

### A.8 — Kontrol Teknologi

| Kontrol | Status | Temuan & Bukti |
|---|---|---|
| **A.8.1** User endpoint devices | ➖ Tidak dinilai | Tidak ada kebijakan endpoint di kode. |
| **A.8.2** Privileged access rights | ✅ Baik | Role `super-admin` eksplisit + Gate bypass (`config/filament-shield.php:72-77`), hanya super-admin bisa menugaskan super-admin (`UserForm.php:56-59`). Catatan: bypass Gate = hak istimewa permanen; batasi jumlah akun. |
| **A.8.5** Secure authentication | 🟡 Sebagian | Rate limit login 5/menit (`vendor/.../Login.php:70`), Timebox anti-timing (`:87`), `session()->regenerate()` (`:177`), CSRF + `AuthenticateSession` (`AdminPanelProvider.php:71-84`). Kosong: **tidak ada MFA** (`HasAuth.php:103`), **password reset tidak aktif** (`HasAuth.php:77`). |
| **A.8.7** Protection against malware | 🟡 Sebagian | Anti-XSS/sanitasi kuat, upload tervalidasi MIME. Tidak ada antivirus scan unggahan. |
| **A.8.9** Configuration management | ✅ Baik | Secret via `env()`, `.gitignore` lindungi `.env`, `APP_DEBUG=false` produksi (`.env.production.example:8`), `pondok:upgrade` jaga blok cPanel `.htaccess`. |
| **A.8.10** Information deletion | ❌ Lemah | Tidak ada anonymize/redact/retention/purge. Data santri lulus/keluar disimpan selamanya (hanya soft delete `Student.php:26`). |
| **A.8.11** Data masking | 🟡 Sebagian | Masking nomor dokumen (`Document.php:159-176`, `DocumentResource.php:155-164`), masking nama di verifikasi surat (`IssuedLetter.php:114-130`). Belum ada masking NISN/NIK/telepon di panel. |
| **A.8.12** Data leakage prevention | 🟡 Sebagian | File sensitif di disk `private` (`serve=false`), unduh via gate, upload divalidasi, `nosniff`. Celah: `local` disk `serve=true` (harus dipastikan tidak dipakai file sensitif), `SESSION_ENCRYPT=false`. |
| **A.8.15** Logging | 🟡 Sebagian | Activity log luas (10 model + 17 action), whitelist `logOnly` melindungi PII (`Applicant.php:96-106`). Lemah: NIS/`registration_no` polos di file log (`GenerateMonthlyInvoices.php:386-393`), production `single` tanpa rotasi (`logging.php:61-66`), log tidak tamper-evident. |
| **A.8.16** Monitoring activities | 🟡 Sebagian | Ada `/up` (`bootstrap/app.php:18`), `pondok:doctor` 21 cek (`CheckSystemHealth.php:25-29`), scheduler heartbeat (`routes/console.php:10-12`). Kosong: **tidak ada alerting otomatis**. |
| **A.8.17** Clock synchronisation | 🟡 Sebagian | Timezone konsisten `Asia/Jakarta` (`config/app.php:68`). Tidak ada pengecekan drift NTP di level aplikasi. |
| **A.8.19** Installation of software | ✅ Baik | `pondok:install` cek server + `APP_KEY` + validasi password (`InstallCommand.php:62,115-124,206-219`). |
| **A.8.20** Network security | ✅ Baik | `TrustProxies` default mati (`AppServiceProvider.php:86-95`), `ForcePortalRootUrl` anti host-header poisoning. |
| **A.8.21** Security of network services | 🟡 Sebagian | Header portal lengkap (CSP, nosniff, referrer-policy). Lemah: **HSTS tidak diset** dan CSP tidak diterapkan ke `/admin`. |
| **A.8.24** Use of cryptography | 🟡 Sebagian | Password bcrypt-12, token PSB di-hash (`ResetApplicantToken.php:41-43`), `documents.number` encrypted (`Document.php:108`), AES-256-CBC. **PII utama plaintext** (NISN, tanggal lahir, alamat, telepon, `issued_letters.snapshot`), sesi DB tidak terenkripsi. |
| **A.8.25** Secure development life cycle | ✅ Baik | `composer.lock` terkunci, test otomatis masif, proses upgrade terdefinisi, `pondok:doctor`. |
| **A.8.28** Secure coding | ✅ Baik | Tanpa SQLi (semua binding/konstanta), tanpa exec/eval, XSS disanitasi (`SanitizeContentHtml.php`), upload tervalidasi MIME+ukuran, honeypot anti-spam. |
| **A.8.29** Security testing | ✅ Baik | 298 file test Pest; test keamanan eksplisit: XSS, CSRF, rate limit, privasi, otorisasi, upload, token. |
| **A.8.31** Separation | 🟡 Sebagian | RBAC kuat + pemisahan env dev/prod. Belum ada separation of duties formal (mis. pemisah pembuat vs penyetuju transaksi). |

## Rekomendasi Prioritas (Residual Risk)

Diurutkan dari yang paling penting:

1. **Paksa cookie & sesi aman di produksi** — tambah `SESSION_SECURE_COOKIE=true` dan `SESSION_ENCRYPT=true` di `.env.production.example`.
2. **Enkripsi at-rest PII** — terapkan cast `encrypted` untuk `students.nisn`, `birth_place/birth_date/address`, `guardians.phone/address`, `staff.nip/phone`, `applicants.nisn/guardian_phone`, dan `issued_letters.snapshot` (perhitungkan dampak performa pencarian).
3. **Aktifkan MFA** untuk akun privilese (bendahara/admin) — Filament 5 sudah mendukung `->multiFactorAuthentication()`.
4. **Aktifkan password reset** (`->passwordReset()`) dan **perkuat password policy** (`Password::defaults()` + `confirmed` + `uncompromised()`).
5. **Terapkan HSTS** di `public/.htaccess` dan **CSP/header keamanan ke panel `/admin`**.
6. **Definisikan retention/anonymisasi** data santri lulus/keluar (A.8.10).
7. **Tambahkan backup DB** (mis. `spatie/laravel-backup` + jadwal) atau dokumentasikan/otomasi backup cPanel.
8. **Tambahkan alerting** (webhook/email saat error kritis) dan jadwalkan `activity:clean`.
9. **Redaksi PII dari file log** — ganti `nis`/`registration_no` dengan ID/hash.
10. **Enforce email verification** (`->emailVerification()` + cek `email_verified_at` di `canAccessPanel`).

## Bukti Tidak Ada Temuan Kritis

- Tidak ada secret produksi hardcoded (pencarian di `app/`, `config/`, `resources/`).
- Tidak ada interpolasi input user ke SQL — semua pakai binding atau konstanta statis.
- Tidak ada `exec`/`shell_exec`/`eval`/`unserialize` di kode aplikasi.
- Tidak ada `$guarded = []` — semua model pakai `#[Fillable]` allowlist.
- Upload file divalidasi MIME dari isi berkas, disimpan dengan nama acak, di luar folder web.
- Pengecualian CSRF hanya pada rute portal publik yang stateless & GET-only, dan sudah diuji.

---

## Catatan Implementasi

**Diperbarui**: 17 Oktober 2026

Batch pertama yang dieksekusi: **kelompok aman** — hanya perubahan pengaturan, tanpa menyentuh logika kode.

| Rekomendasi | Status | Perubahan |
|---|---|---|
| #1 Cookie & sesi aman di produksi | ✅ Selesai | `.env.production.example`: `SESSION_ENCRYPT=true` dan `SESSION_SECURE_COOKIE=true`. |
| #5 HSTS (paksa HTTPS) | ✅ Selesai (sebagian) | `public/.htaccess`: header `Strict-Transport-Security: max-age=31536000` di dalam `<IfModule mod_headers.c>`. |
| #5 CSP di `/admin` | ⏳ Belum | Ditunda — butuh konfigurasi hati-hati agar Filament/Livewire tidak rusak. |

**File yang diubah:**

- `.env.production.example` — `SESSION_ENCRYPT` diubah dari `false` menjadi `true`, dan menambah baris `SESSION_SECURE_COOKIE=true`.
- `public/.htaccess` — menambah blok header HSTS.

**Yang sengaja TIDAK diubah:**

- `.env.example` (template pengembangan lokal) dibiarkan — supaya login lewat `http://localhost` tetap berfungsi.

**Peringatan untuk operator produksi:**

- `SESSION_SECURE_COOKIE=true` berarti login hanya bisa lewat HTTPS. Pastikan AutoSSL/HTTPS sudah aktif sebelum login pertama.
- `SESSION_ENCRYPT=true` berarti sesi lama yang tersimpan polos akan kedaluwarsa sekali setelah update — pengguna cukup login ulang.
