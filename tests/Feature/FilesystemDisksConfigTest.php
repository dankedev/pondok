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

    // env() membaca $_ENV/$_SERVER lebih dulu daripada putenv; di CI .env hasil
    // salinan .env.example sudah mengisi AWS_BUCKET= (kosong) di sana.
    $previous = [];

    foreach ($keys as $key) {
        $previous[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null, array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null, getenv($key)];

        if (isset($env[$key])) {
            $_ENV[$key] = $_SERVER[$key] = $env[$key];
            putenv("{$key}={$env[$key]}");
        } else {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    try {
        return require config_path('filesystems.php');
    } finally {
        foreach ($previous as $key => [$hadEnv, $envValue, $hadServer, $serverValue, $processValue]) {
            $hadEnv ? $_ENV[$key] = $envValue : null;
            $hadServer ? $_SERVER[$key] = $serverValue : null;
            $processValue === false ? putenv($key) : putenv("{$key}={$processValue}");
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
