<?php

return [

    /*
    | Akun super-admin yang dibuat oleh SuperAdminSeeder saat instalasi.
    */
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    /*
    | Proksi tepercaya (Cloudflare, reverse proxy cPanel) agar IP pengunjung
    | untuk rate limit PSB benar. Daftar IP/CIDR dipisah koma, atau `*`.
    | Kosong = tidak ada proksi tepercaya; header X-Forwarded-For diabaikan.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    | Document root terpisah dari folder aplikasi (cPanel yang document root
    | domainnya tidak bisa diubah), mis. `/home/<user>/public_html`. Bila diisi,
    | `public_path()` menunjuk ke sini dan `pondok:publish-public` menyalin isi
    | `public/` ke sana.
    */
    'public_path' => env('PONDOK_PUBLIC_PATH'),

    /*
    | Portal publik (F017). `cache_max_age` dalam detik untuk Cache-Control
    | publik respons 200 (0 = tanpa cache publik). `csp_enabled` mengirim
    | Content-Security-Policy portal (otomatis tidak dikirim saat Vite hot).
    */
    'portal' => [
        'cache_max_age' => (int) env('PORTAL_CACHE_MAX_AGE', 300),
        'csp_enabled' => (bool) env('PORTAL_CSP', true),
    ],

    /*
    | Data demo (F034, `php artisan db:seed --class=DemoSeeder`). Password semua
    | akun demo; kosong = password bawaan `DemoSeeder::DEFAULT_PASSWORD` yang
    | tertulis di docs/demo.md (seeder menampilkan peringatan).
    */
    'demo' => [
        'password' => env('DEMO_PASSWORD'),
    ],

];
