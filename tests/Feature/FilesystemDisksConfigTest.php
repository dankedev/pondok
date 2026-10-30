<?php

/**
 * F101: disk `private` dan `public` bisa dialihkan ke S3/R2 lewat .env, dan
 * tetap lokal bila variabelnya tidak diisi.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function loadFilesystemsConfig(array $env): array
{
    $keys = ['PRIVATE_DISK_DRIVER', 'PUBLIC_DISK_DRIVER', 'AWS_BUCKET', 'AWS_PUBLIC_BUCKET', 'AWS_PUBLIC_URL', 'AWS_ENDPOINT'];

    foreach ($keys as $key) {
        isset($env[$key]) ? putenv("{$key}={$env[$key]}") : putenv($key);
    }

    try {
        return require config_path('filesystems.php');
    } finally {
        foreach ($keys as $key) {
            putenv($key);
        }
    }
}

it('F101: keeps private and public disks local by default', function () {
    $disks = loadFilesystemsConfig([])['disks'];

    expect($disks['private']['driver'])->toBe('local')
        ->and($disks['public']['driver'])->toBe('local')
        ->and($disks['public']['url'])->toEndWith('/storage');
});

it('F101: switches private and public to separate s3 buckets with their own url', function () {
    $disks = loadFilesystemsConfig([
        'PRIVATE_DISK_DRIVER' => 's3',
        'PUBLIC_DISK_DRIVER' => 's3',
        'AWS_BUCKET' => 'pondok-private',
        'AWS_PUBLIC_BUCKET' => 'pondok-public',
        'AWS_PUBLIC_URL' => 'https://media.contoh.sch.id',
        'AWS_ENDPOINT' => 'https://abc.r2.cloudflarestorage.com',
    ])['disks'];

    expect($disks['private'])->toMatchArray(['driver' => 's3', 'bucket' => 'pondok-private', 'url' => null, 'endpoint' => 'https://abc.r2.cloudflarestorage.com'])
        ->and($disks['public'])->toMatchArray(['driver' => 's3', 'bucket' => 'pondok-public', 'url' => 'https://media.contoh.sch.id'])
        ->and($disks['private'])->not->toHaveKey('visibility');
});
