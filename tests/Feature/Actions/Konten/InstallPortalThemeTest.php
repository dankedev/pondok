<?php

use App\Actions\Konten\InstallPortalTheme;
use App\Actions\Konten\ListPortalThemes;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/*
| F103 AC-8, AC-9, AC-10. Kontrak: `InstallPortalTheme::handle(array $entry, string $bytes, ?User $actor = null): void`.
| Penolakan = `InstallPortalTheme` melempar `InvalidArgumentException` berpesan `pondok.theme_install.errors.<alasan>`
| (`invalid_slug`, `official_slug`, `size_mismatch`, `sha256_mismatch`, `signature_invalid`, `key_unknown`, `version_not_newer`, `locked`)
| atau pesan validator `pondok.theme_schema.*`; kegagalan tulis di tengah pasang melempar Throwable apa pun. Setiap penolakan tidak
| mengubah apa pun di folder terpasang (tanpa sisa `_staging/<slug>`), tidak menyentuh `theme.*`, dan tidak menulis log.
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    $this->keys = themeTestKeys(['k1', 'k2']);
    $this->admin = kontenUser('super-admin');
});

function ipInstall(array $package, $actor = null): void
{
    app(InstallPortalTheme::class)->handle($package['entry'], $package['bytes'], $actor);
}

it('AC-8: installs a signed theme byte for byte, lists it, and logs the install with the actor', function () {
    $package = signedThemePackage('zz-uji', '1.0.0', ['name' => 'Tema pasang uji é']);

    ipInstall($package, $this->admin);

    $log = kontenActivities()->where('description', 'portal_theme_installed');

    expect(themeInstalledFile('zz-uji'))->toBe($package['bytes'])
        ->and(themeInstalledFile('zz-uji', 'previous.json'))->toBeNull()
        ->and(is_dir(themeInstalledSandbox().'/_staging/zz-uji'))->toBeFalse()
        ->and(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', 'zz-uji'))
        ->toMatchArray(['source' => 'installed', 'valid' => true, 'name' => 'Tema pasang uji é', 'version' => '1.0.0'])
        ->and($log)->toHaveCount(1)
        ->and($log->first()->properties['slug'])->toBe('zz-uji')
        ->and($log->first()->properties['version'])->toBe('1.0.0')
        ->and($log->first()->causer_id)->toBe($this->admin->id)
        ->and(Setting::get('theme.template'))->toBeNull();
});

it('AC-8: accepts signatures from both embedded keys, k1 and k2', function () {
    ipInstall(signedThemePackage('zz-kunci-satu', keyId: 'k1'));
    ipInstall(signedThemePackage('zz-kunci-dua', keyId: 'k2'));

    expect(themeInstalledFile('zz-kunci-satu'))->not->toBeNull()
        ->and(themeInstalledFile('zz-kunci-dua'))->not->toBeNull();
});

it('AC-8: rejects a bad hash, size, signature or key id and leaves the installed folder and settings untouched', function () {
    Setting::set('theme.template', 'zz-uji');
    themeInstalledWrite('zz-uji', [...themeBaseManifest(), 'version' => '1.0.0']);
    $good = signedThemePackage('zz-uji', '2.0.0');
    $tampered = str_replace('Tema dasar uji', 'Tema dibajak', $good['bytes']);
    $outsider = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

    $cases = [
        'sha256 wrong' => [['entry' => [...$good['entry'], 'sha256' => str_repeat('0', 64)], 'bytes' => $good['bytes']], 'sha256_mismatch'],
        'sha256 of other bytes' => [['entry' => [...$good['entry'], 'sha256' => hash('sha256', $tampered)], 'bytes' => $good['bytes']], 'sha256_mismatch'],
        'size smaller than download' => [['entry' => [...$good['entry'], 'size' => strlen($good['bytes']) - 1], 'bytes' => $good['bytes']], 'size_mismatch'],
        'size larger than download' => [['entry' => [...$good['entry'], 'size' => strlen($good['bytes']) + 1], 'bytes' => $good['bytes']], 'size_mismatch'],
        'bytes changed after signing' => [['entry' => [...$good['entry'], 'size' => strlen($tampered), 'sha256' => hash('sha256', $tampered)], 'bytes' => $tampered], 'signature_invalid'],
        'signed by a key that is not embedded' => [['entry' => themeCatalogEntry('zz-uji', '2.0.0', $good['bytes'], secret: $outsider), 'bytes' => $good['bytes']], 'signature_invalid'],
        'signed by k2 but declared k1' => [['entry' => themeCatalogEntry('zz-uji', '2.0.0', $good['bytes'], keyId: 'k1', secret: $this->keys['k2']['secret']), 'bytes' => $good['bytes']], 'signature_invalid'],
        'key id unknown' => [['entry' => [...$good['entry'], 'key_id' => 'k9'], 'bytes' => $good['bytes']], 'key_unknown'],
        'signature truncated' => [['entry' => [...$good['entry'], 'signature' => substr($good['entry']['signature'], 0, 40)], 'bytes' => $good['bytes']], null],
        'signature not base64' => [['entry' => [...$good['entry'], 'signature' => '!!bukan-base64!!'], 'bytes' => $good['bytes']], null],
        'signature empty' => [['entry' => [...$good['entry'], 'signature' => ''], 'bytes' => $good['bytes']], null],
    ];

    $before = themeInstalledTree();

    foreach ($cases as $label => [$package, $reason]) {
        themeAssertRejected(fn () => ipInstall($package, $this->admin), $reason);

        expect(themeInstalledTree())->toBe($before, $label)
            ->and(Setting::get('theme.template'))->toBe('zz-uji', $label);
    }

    expect(kontenActivities()->where('description', 'portal_theme_installed'))->toHaveCount(0);
});

it('AC-8: turns an unusable embedded public key (empty, invalid base64, wrong length) into a plain refusal, not a Sodium exception', function () {
    $package = signedThemePackage('zz-uji');

    foreach (['empty' => '', 'not base64' => 'bukan base64 !!', 'too short' => base64_encode(random_bytes(16)), 'too long' => base64_encode(random_bytes(48))] as $label => $key) {
        config(['pondok.theme_public_keys' => ['k1' => $key, 'k2' => $key]]);

        themeAssertRejected(fn () => ipInstall($package), null);

        expect(themeInstalledTree())->toBe([], $label);
    }

    config(['pondok.theme_public_keys' => []]);
    themeAssertRejected(fn () => ipInstall($package), 'key_unknown');
});

it('AC-8: rejects a traversal, malformed or official slug before any path is built, touching nothing outside the theme folder', function () {
    $base = dirname(themeInstalledSandbox());
    mkdir("{$base}/victim", 0777);
    file_put_contents("{$base}/victim/theme.json", 'AMAN');
    file_put_contents("{$base}/victim/previous.json", 'AMAN');
    $before = themeInstalledBaseTree();

    $malformed = ['../victim', 'zz/../x', '..', '', 'ZZ-BESAR', 'x', "zz-ok\n", 'zz ok', 'zz_ok', str_repeat('a', 41), '../../etc/zz', '/tmp/zz', '.hidden', 'zz\\x'];

    foreach ($malformed as $slug) {
        $package = signedThemePackage('zz-uji', entry: ['slug' => $slug]);

        themeAssertRejected(fn () => ipInstall($package, $this->admin), 'invalid_slug');

        expect(themeInstalledBaseTree())->toBe($before, json_encode($slug));
    }

    foreach (THEME_OFFICIAL as $slug) {
        themeAssertRejected(fn () => ipInstall(signedThemePackage($slug, entry: ['slug' => $slug]), $this->admin), 'official_slug');

        expect(themeInstalledBaseTree())->toBe($before, $slug);
    }
});

it('AC-8: rejects a manifest whose slug or version differs from the signed catalog entry', function () {
    $before = themeInstalledTree();

    themeAssertRejected(fn () => ipInstall(signedThemePackage('zz-uji', manifest: ['slug' => 'zz-lain'])));
    expect(themeInstalledTree())->toBe($before);

    themeAssertRejected(fn () => ipInstall(signedThemePackage('zz-uji', '1.1.0', entry: ['version' => '1.2.0'])));
    expect(themeInstalledTree())->toBe($before);
});

it('AC-8: rejects an unsupported schema, a min_core above the core, and JSON or UTF-8 that is broken, even when properly signed', function () {
    $before = themeInstalledTree();
    $cases = [
        'pondok.theme_schema.unsupported_version' => signedThemePackage('zz-uji', manifest: ['schema_version' => 99]),
        'pondok.theme_schema.core_too_old' => signedThemePackage('zz-uji', manifest: ['min_core' => '999.0.0']),
        'pondok.theme_schema.invalid_json' => ['entry' => themeCatalogEntry('zz-uji', '1.0.0', '{"schema_version":'), 'bytes' => '{"schema_version":'],
        'pondok.theme_schema.invalid_utf8' => ['entry' => themeCatalogEntry('zz-uji', '1.0.0', "{\"name\":\"\xff\xfe\"}"), 'bytes' => "{\"name\":\"\xff\xfe\"}"],
    ];

    foreach ($cases as $reason => $package) {
        themeAssertRejected(fn () => ipInstall($package), $reason);

        expect(themeInstalledTree())->toBe($before, $reason);
    }
});

it('AC-9: accepts a higher version and keeps exactly the previous theme.json as previous.json', function () {
    $first = signedThemePackage('zz-uji', '1.2.0');
    $second = signedThemePackage('zz-uji', '1.3.0', ['name' => 'Tema versi dua']);
    $third = signedThemePackage('zz-uji', '2.0.0', ['name' => 'Tema versi tiga']);

    ipInstall($first);
    ipInstall($second, $this->admin);

    expect(themeInstalledFile('zz-uji'))->toBe($second['bytes'])
        ->and(themeInstalledFile('zz-uji', 'previous.json'))->toBe($first['bytes']);

    ipInstall($third, $this->admin);

    expect(themeInstalledFile('zz-uji'))->toBe($third['bytes'])
        ->and(themeInstalledFile('zz-uji', 'previous.json'))->toBe($second['bytes'])
        ->and(kontenActivities()->where('description', 'portal_theme_installed'))->toHaveCount(2);
});

it('AC-9: compares versions as numbers, so 1.10.0 is newer than 1.9.0', function () {
    ipInstall(signedThemePackage('zz-uji', '1.9.0'));
    ipInstall(signedThemePackage('zz-uji', '1.10.0'));

    expect(json_decode((string) themeInstalledFile('zz-uji'), true)['version'])->toBe('1.10.0');
});

it('AC-9: refuses a lower or equal version than the valid installed one (replay) without changing anything', function () {
    $installed = signedThemePackage('zz-uji', '1.2.0');
    ipInstall($installed);
    $before = themeInstalledTree();

    foreach (['1.1.9', '1.2.0', '1.0.0', '0.9.0'] as $version) {
        themeAssertRejected(fn () => ipInstall(signedThemePackage('zz-uji', $version), $this->admin), 'version_not_newer');

        expect(themeInstalledTree())->toBe($before, $version);
    }

    expect(themeInstalledFile('zz-uji'))->toBe($installed['bytes']);
});

it('AC-9: lets a broken installed theme be reinstalled, even at the same or a lower version, because only a valid install is compared', function () {
    themeInstalledWrite('zz-rusak', [...themeBaseManifest(), 'version' => '5.0.0', 'schema_version' => 99]);
    $package = signedThemePackage('zz-rusak', '5.0.0');

    ipInstall($package, $this->admin);

    expect(themeInstalledFile('zz-rusak'))->toBe($package['bytes']);

    themeInstalledWrite('zz-garbled', '{"tidak valid');
    ipInstall(signedThemePackage('zz-garbled', '1.0.0'));

    expect(json_decode((string) themeInstalledFile('zz-garbled'), true)['version'])->toBe('1.0.0');
});

it('AC-10: leaves theme.json and previous.json intact and cleans up when the swap fails midway', function () {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('Folder baca-saja tidak menghalangi root.');
    }

    $first = signedThemePackage('zz-uji', '1.0.0');
    $second = signedThemePackage('zz-uji', '2.0.0');
    ipInstall($first, $this->admin);
    ipInstall($second, $this->admin);
    $before = themeInstalledTree();
    $dir = themeInstalledSandbox().'/zz-uji';

    chmod($dir, 0555);

    try {
        expect(fn () => ipInstall(signedThemePackage('zz-uji', '3.0.0'), $this->admin))->toThrow(Exception::class);
    } finally {
        chmod($dir, 0755);
    }

    expect(themeInstalledTree())->toBe($before)
        ->and(themeInstalledFile('zz-uji'))->toBe($second['bytes'])
        ->and(themeInstalledFile('zz-uji', 'previous.json'))->toBe($first['bytes'])
        ->and(glob("{$dir}/*.tmp"))->toBe([])
        ->and(is_dir(themeInstalledSandbox().'/_staging/zz-uji'))->toBeFalse()
        ->and(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', 'zz-uji')['valid'])->toBeTrue()
        ->and(kontenActivities()->where('description', 'portal_theme_installed'))->toHaveCount(2);
});

/**
 * Invarian pasang atomik (AC-10): `theme.json` selalu berkas utuh dan valid (salah satu dari `$allowed`, byte persis), `previous.json` persis
 * `$previous` (null = tidak ada), tak ada sisa `*.tmp` maupun `_staging/<slug>`, dan tema tetap terdaftar valid.
 *
 * @param  list<string>|null  $allowed  null = `theme.json` harus tidak ada
 */
function ipAssertAtomicInvariant(string $slug, ?array $allowed, ?string $previous, string $label): void
{
    $root = themeInstalledSandbox();
    $theme = themeInstalledFile($slug);
    $temporary = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)))
        ->map(fn (SplFileInfo $file): string => $file->getPathname())->filter(fn (string $path): bool => str_ends_with($path, '.tmp'))->values()->all();

    if ($allowed === null) {
        expect($theme)->toBeNull($label);
    } else {
        expect(in_array($theme, $allowed, true))->toBeTrue("{$label}: theme.json harus utuh (lama atau baru)")
            ->and(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', $slug)['valid'] ?? null)->toBeTrue($label);
    }

    expect(themeInstalledFile($slug, 'previous.json'))->toBe($previous, "{$label}: previous.json")
        ->and($temporary)->toBe([], "{$label}: sisa .tmp")
        ->and(is_dir("{$root}/_staging/{$slug}"))->toBeFalse("{$label}: sisa staging");
}

/**
 * Jalankan pasang yang harus gagal; izin folder dikembalikan apa pun hasilnya.
 *
 * @param  array<string, int>  $modes  path => mode saat pasang
 */
function ipInstallFailing(array $package, array $modes, ?Closure $before = null, ?Closure $after = null): void
{
    $original = [];

    foreach ($modes as $path => $mode) {
        $original[$path] = fileperms($path) & 0777;
        chmod($path, $mode);
    }

    $before?->__invoke();

    try {
        expect(fn () => app(InstallPortalTheme::class)->handle($package['entry'], $package['bytes']))->toThrow(Exception::class);
    } finally {
        $after?->__invoke();

        foreach ($original as $path => $mode) {
            chmod($path, $mode);
        }
    }
}

/**
 * Tahan penggantian berkas (rename di atasnya gagal) lewat flag immutable; false bila platform tak mendukung tanpa root.
 */
function ipImmutable(string $path, bool $on): bool
{
    if (PHP_OS_FAMILY !== 'Darwin') {
        return false;
    }

    exec('chflags '.($on ? 'uchg' : 'nouchg').' '.escapeshellarg($path).' 2>&1', $output, $code);

    return $code === 0;
}

it('AC-10: a first install that fails writing the staging file or placing theme.json leaves nothing behind', function () {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('Folder baca-saja tidak menghalangi root.');
    }

    $root = themeInstalledSandbox();
    mkdir("{$root}/_staging", 0777);
    $package = signedThemePackage('zz-uji', '1.0.0');

    // Menulis berkas staging gagal: folder `_staging` tak bisa ditulis.
    ipInstallFailing($package, ["{$root}/_staging" => 0555]);
    ipAssertAtomicInvariant('zz-uji', null, null, 'staging tak bisa ditulis');
    expect(is_dir("{$root}/zz-uji"))->toBeFalse();

    // Membuat folder tema gagal: folder terpasang tak bisa ditulis (staging sudah berhasil ditulis).
    ipInstallFailing($package, [$root => 0555]);
    ipAssertAtomicInvariant('zz-uji', null, null, 'folder terpasang tak bisa ditulis');
    expect(is_dir("{$root}/zz-uji"))->toBeFalse();

    // Rename staging ke theme.json gagal: folder tema sudah ada tetapi kosong dan tak bisa ditulis.
    mkdir("{$root}/zz-uji", 0777);
    ipInstallFailing($package, ["{$root}/zz-uji" => 0555]);
    ipAssertAtomicInvariant('zz-uji', null, null, 'rename ke theme.json gagal');

    // Setelah semua kegagalan itu, pasang yang normal tetap berhasil.
    ipInstall($package);

    expect(themeInstalledFile('zz-uji'))->toBe($package['bytes']);
});

it('AC-10: an update that fails writing the staging file leaves theme.json and previous.json exactly as they were', function () {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('Folder baca-saja tidak menghalangi root.');
    }

    $first = signedThemePackage('zz-uji', '1.0.0');
    $second = signedThemePackage('zz-uji', '2.0.0');
    ipInstall($first);
    ipInstall($second);
    $root = themeInstalledSandbox();
    $before = themeInstalledTree();

    ipInstallFailing(signedThemePackage('zz-uji', '3.0.0'), ["{$root}/_staging" => 0555]);

    ipAssertAtomicInvariant('zz-uji', [$second['bytes']], $first['bytes'], 'staging tak bisa ditulis');
    expect(themeInstalledTree())->toBe($before);
});

it('AC-10: an update whose rename of staging onto theme.json fails keeps the old theme.json and previous.json and cleans up', function () {
    $first = signedThemePackage('zz-uji', '1.0.0');
    $second = signedThemePackage('zz-uji', '2.0.0');
    ipInstall($first);
    ipInstall($second);
    $target = themeInstalledSandbox().'/zz-uji/theme.json';

    if (! ipImmutable($target, true)) {
        $this->markTestSkipped('Butuh `chflags uchg` (macOS) untuk menahan rename di atas theme.json; Linux membutuhkan root (`chattr +i`).');
    }

    $third = signedThemePackage('zz-uji', '3.0.0');

    try {
        expect(fn () => app(InstallPortalTheme::class)->handle($third['entry'], $third['bytes']))->toThrow(Exception::class);
    } finally {
        ipImmutable($target, false);
    }

    ipAssertAtomicInvariant('zz-uji', [$second['bytes']], $first['bytes'], 'rename staging ke theme.json gagal');
});

it('AC-10: an update whose final rename of previous.json fails never leaves a missing or partial theme.json or a partial previous.json', function () {
    $first = signedThemePackage('zz-uji', '1.0.0');
    $second = signedThemePackage('zz-uji', '2.0.0');
    $third = signedThemePackage('zz-uji', '3.0.0');
    ipInstall($first);
    $dir = themeInstalledSandbox().'/zz-uji';

    // `previous.json` berupa folder berisi: rename `.tmp` ke atasnya selalu gagal, tepat di langkah terakhir.
    mkdir("{$dir}/previous.json");
    file_put_contents("{$dir}/previous.json/penanda", 'UTUH');

    expect(fn () => app(InstallPortalTheme::class)->handle($second['entry'], $second['bytes']))->toThrow(Exception::class);

    // Kegagalan setelah rename theme.json boleh meninggalkan theme.json baru; yang tak boleh: hilang, terpotong, atau previous.json tertimpa sebagian.
    $theme = themeInstalledFile('zz-uji');

    expect(in_array($theme, [$first['bytes'], $second['bytes']], true))->toBeTrue('theme.json harus utuh (lama atau baru)')
        ->and(file_get_contents("{$dir}/previous.json/penanda"))->toBe('UTUH')
        ->and(glob("{$dir}/*.tmp"))->toBe([])
        ->and(is_dir(themeInstalledSandbox().'/_staging/zz-uji'))->toBeFalse()
        ->and(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', 'zz-uji')['valid'])->toBeTrue();

    // Setelah hambatan dibuang, pasang berikutnya normal dan previous.json = versi yang berlaku sebelumnya.
    File::deleteDirectory("{$dir}/previous.json");
    $current = themeInstalledFile('zz-uji');
    ipInstall($third);

    expect(themeInstalledFile('zz-uji'))->toBe($third['bytes'])
        ->and(themeInstalledFile('zz-uji', 'previous.json'))->toBe($current);
});

it('AC-10: refuses with a clear message while the write lock of the same slug is held, and keeps the files intact', function () {
    $installed = signedThemePackage('zz-uji', '1.0.0');
    ipInstall($installed);
    $before = themeInstalledTree();
    $lock = Cache::lock('themes.write.zz-uji', 10);
    expect($lock->get())->toBeTrue();

    try {
        themeAssertRejected(fn () => ipInstall(signedThemePackage('zz-uji', '2.0.0')), 'locked');
        expect(themeInstalledTree())->toBe($before);

        ipInstall(signedThemePackage('zz-lain', '1.0.0'));
        expect(themeInstalledFile('zz-lain'))->not->toBeNull();
    } finally {
        $lock->release();
    }

    ipInstall(signedThemePackage('zz-uji', '2.0.0'));

    expect(json_decode((string) themeInstalledFile('zz-uji'), true)['version'])->toBe('2.0.0');
});

it('AC-8: has Indonesian text for every refusal reason', function () {
    expectTranslatedKeys(array_map(
        fn (string $reason): string => "pondok.theme_install.errors.{$reason}",
        ['invalid_slug', 'official_slug', 'size_mismatch', 'sha256_mismatch', 'signature_invalid', 'key_unknown', 'version_not_newer', 'locked'],
    ));
});
