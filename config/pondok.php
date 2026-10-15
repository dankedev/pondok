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

];
