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

];
