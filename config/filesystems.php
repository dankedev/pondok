<?php

// Sambungan S3/R2 bersama untuk disk `private` dan `public` (bila dialihkan lewat
// .env). R2 tidak mengenal ACL, jadi `visibility` sengaja tidak diisi.
$s3Connection = [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => false,
    'report' => false,
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // PRIVATE_DISK_DRIVER=s3 memindahkan dokumen santri, berkas PSB, bukti bayar,
        // dan ekspor keuangan ke bucket S3/R2 (AWS_BUCKET). Bucket ini harus privat;
        // unduhan tetap lewat route aplikasi yang dicek permission.
        'private' => env('PRIVATE_DISK_DRIVER', 'local') === 's3' ? [
            ...$s3Connection,
            'bucket' => env('AWS_BUCKET'),
            'url' => null,
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // PUBLIC_STORAGE_ROOT: isi dengan `<document root>/storage` bila hosting
        // mematikan symlink, sehingga berkas publik disimpan langsung di folder web.
        // PUBLIC_DISK_DRIVER=s3 memindahkan galeri, sampul berita, dan logo ke bucket
        // AWS_PUBLIC_BUCKET yang terbuka untuk publik, diakses lewat AWS_PUBLIC_URL
        // (domain publik bucket atau custom domain).
        'public' => env('PUBLIC_DISK_DRIVER', 'local') === 's3' ? [
            ...$s3Connection,
            'bucket' => env('AWS_PUBLIC_BUCKET'),
            'url' => env('AWS_PUBLIC_URL'),
        ] : [
            'driver' => 'local',
            'root' => env('PUBLIC_STORAGE_ROOT', storage_path('app/public')),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        // Config dimuat sebelum provider, jadi PONDOK_PUBLIC_PATH dibaca langsung dari env.
        rtrim(env('PONDOK_PUBLIC_PATH') ?: public_path(), '/').'/storage' => env('PUBLIC_STORAGE_ROOT', storage_path('app/public')),
    ],

];
