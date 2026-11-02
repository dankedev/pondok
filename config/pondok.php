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
    | Direktori tema portal (F102): satu subfolder per tema berisi `theme.json`.
    | Bawaan `resources/themes` (tiga tema resmi); test mengarahkannya ke folder sementara.
    */
    'themes_path' => resource_path('themes'),

    /*
    | Tema resmi terpasang dari katalog (F103): `<slug>/theme.json` (+ `previous.json`), ditulis aplikasi, di luar web root.
    */
    'installed_themes_path' => storage_path('app/themes'),

    /*
    | Katalog tema resmi (F103). URL dapat diganti env hanya untuk uji, bukan pengaturan admin. Kunci publik Ed25519
    | (base64) sengaja literal di sini, tidak dari env: `k1` utama, `k2` cadangan (privatnya disimpan terpisah offline).
    | Kunci kosong membuat pemasangan tema ditolak dengan pesan jelas. Kunci privat tidak ada di repo (docs/rilis.md).
    */
    'theme_catalog_url' => env('PONDOK_THEME_CATALOG_URL', 'https://pondok.org/themes/themes.json'),
    'theme_public_keys' => [
        'k1' => 'iBp6Ju+fcrjdcH2jps6A6QNXUvkeZ6pisMR0FHL3Piw=',
        'k2' => 'EJFcfG8aypTKgociXiahFy2gdmByxqKZ3U2ZNOqA6xk=',
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
