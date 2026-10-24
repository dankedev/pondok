# F086 inti-header-keamanan-admin — Header keamanan panel admin (CSP report-only)

Issue: #97
Status rencana: jalur cepat (tanpa review rencana)
Jalur: cepat. Tanpa migration, tanpa perubahan data, uang, PSB, atau hak akses. Pecahan pertama dari #22 (F056).

## Tujuan
Panel admin `/admin` (termasuk halaman masuk) mengirim header keamanan dasar yang sama dengan portal, ditambah
`Content-Security-Policy-Report-Only`. Browser hanya mencatat pelanggaran CSP di console, tidak memblokir, sehingga
Filament/Livewire/Alpine tidak berisiko rusak. Pelanggaran yang tercatat menjadi bahan untuk CSP penuh di #22.

## Keputusan
- **K1 Middleware baru `SetAdminSecurityHeaders`**, didaftarkan paling awal di `->middleware([...])` panel admin (tidak
  persistent). Posisi pertama penting: Laravel memindah `Authenticate` ke depan sesuai daftar prioritas, jadi middleware di
  akhir daftar tidak melihat respons 403/419. Dengan begitu header berlaku berlaku untuk semua route panel: login, reset sandi, halaman, resource, dan route unduhan di `authenticatedRoutes`.
  Request `livewire/update` (JSON) tidak perlu header ini.
- **K2 DRY:** empat header dasar (`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`)
  dipindah ke `SetPortalSecurityHeaders::setBaseHeaders()` dan dipakai kedua middleware. Nilai header portal tidak berubah.
- **K3 CSP admin dikirim selalu sebagai report-only**, tanpa saklar config (tidak bisa memblokir apa pun; YAGNI). Konstanta
  `SetAdminSecurityHeaders::CONTENT_SECURITY_POLICY_REPORT_ONLY`. Tanpa `report-uri`/endpoint report.
- **K4 Isi kebijakan** memperhitungkan Livewire/Alpine dan bawaan Filament yang terpakai sekarang:
  `script-src 'self' 'unsafe-inline' 'unsafe-eval'` (skrip inline Filament + evaluator Alpine), `style-src 'self'
  'unsafe-inline'` (atribut style Alpine/Filament), `img-src 'self' data: blob:` (pratinjau unggah FilePond, ikon SVG),
  `font-src 'self' data:`, `connect-src 'self'`, `worker-src 'self' blob:` (worker pratinjau FilePond, terlihat saat cek
  tampilan), `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'self'`.
- **K6 Avatar `ui-avatars.com` tidak diizinkan.** Avatar bawaan Filament dimuat dari host pihak ketiga (nama user ikut
  terkirim). Pelanggaran `img-src` ini sengaja dibiarkan tercatat dan dilaporkan; perbaikannya (avatar lokal) diputuskan di #22.
- **K5 Portal publik dan portal wali tidak berubah.** Portal wali tetap hanya `AddPrivacyHeaders`.

## File yang diubah
- `app/Http/Middleware/SetPortalSecurityHeaders.php` — ekstrak `setBaseHeaders()`.
- `app/Http/Middleware/SetAdminSecurityHeaders.php` — baru.
- `app/Providers/Filament/AdminPanelProvider.php` — daftarkan middleware.
- `tests/Feature/Http/AdminSecurityHeadersTest.php` — baru.
- `feature_list.json` — status F086.

## Kriteria penerimaan
1. `GET /admin/login` (tamu) mengirim `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
   `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, dan `Content-Security-Policy-Report-Only`.
2. Halaman admin terautentikasi (dashboard, halaman resource) dan respons tolak (403) di panel membawa header yang sama.
3. Panel admin tidak mengirim `Content-Security-Policy` yang memblokir.
4. Header portal publik (`/`) tidak berubah: CSP portal tetap sama dan tidak ada `Content-Security-Policy-Report-Only`.
5. Portal wali (`/wali/masuk`) tidak mendapat header admin; header privasinya tetap.
6. Cek tampilan 360px, 1024px, desktop: login, dashboard, form dengan unggah berkas, modal aksi, notifikasi tetap
   berfungsi tanpa error console. Pelanggaran report-only yang tercatat dilaporkan ke orkestrator.
