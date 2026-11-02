<?php

use App\Actions\Konten\InstallPortalTheme;
use App\Actions\Konten\ListPortalThemes;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

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
    ipInstall($first);
    ipInstall($second);
    $before = themeInstalledTree();
    $dir = themeInstalledSandbox().'/zz-uji';

    chmod($dir, 0555);

    try {
        expect(fn () => ipInstall(signedThemePackage('zz-uji', '3.0.0'), $this->admin))->toThrow(Throwable::class);
    } finally {
        chmod($dir, 0755);
    }

    expect(themeInstalledTree())->toBe($before)
        ->and(themeInstalledFile('zz-uji'))->toBe($second['bytes'])
        ->and(themeInstalledFile('zz-uji', 'previous.json'))->toBe($first['bytes'])
        ->and(glob("{$dir}/*.tmp"))->toBe([])
        ->and(is_dir(themeInstalledSandbox().'/_staging/zz-uji'))->toBeFalse()
        ->and(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', 'zz-uji')['valid'])->toBeTrue()
        ->and(kontenActivities()->where('description', 'portal_theme_installed'))->toHaveCount(1);
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
