<?php

use App\Actions\Konten\InstallPortalTheme;
use App\Actions\Konten\RemovePortalTheme;
use App\Actions\Konten\RollbackPortalTheme;
use App\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;

/*
| F103 AC-11 (dan izin AC-3 di tingkat Action). Kontrak: `RollbackPortalTheme::handle(string $slug, User $actor): void` dan
| `RemovePortalTheme::handle(string $slug, User $actor): void`. Hanya pemegang `manage:themes` (`AuthorizationException` bagi lainnya).
| Penolakan = `InvalidArgumentException` (`pondok.theme_install.errors.invalid_slug|official_slug|no_previous|locked`, atau pesan validator).
| Log `konten`: `portal_theme_rolled_back`, `portal_theme_removed` dengan `slug`, `version` (versi yang berlaku sesudahnya / yang dihapus) dan pelaku.
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    themeTestKeys();
    $this->admin = kontenUser('super-admin');
});

/**
 * Pasang dua versi lewat Action sehingga `previous.json` ada.
 *
 * @return array{old: array<string, mixed>, new: array<string, mixed>}
 */
function rrInstallTwoVersions(string $slug = 'zz-uji'): array
{
    $old = signedThemePackage($slug, '1.0.0');
    $new = signedThemePackage($slug, '2.0.0');

    app(InstallPortalTheme::class)->handle($old['entry'], $old['bytes']);
    app(InstallPortalTheme::class)->handle($new['entry'], $new['bytes']);

    return ['old' => $old, 'new' => $new];
}

it('AC-11: rolls back one version, drops previous.json, and logs it with the actor', function () {
    $versions = rrInstallTwoVersions();

    app(RollbackPortalTheme::class)->handle('zz-uji', $this->admin);

    $log = kontenActivities()->where('description', 'portal_theme_rolled_back');

    expect(themeInstalledFile('zz-uji'))->toBe($versions['old']['bytes'])
        ->and(themeInstalledFile('zz-uji', 'previous.json'))->toBeNull()
        ->and(is_dir(themeInstalledSandbox().'/_staging/zz-uji'))->toBeFalse()
        ->and($log)->toHaveCount(1)
        ->and($log->first()->properties['slug'])->toBe('zz-uji')
        ->and($log->first()->properties['version'])->toBe('1.0.0')
        ->and($log->first()->causer_id)->toBe($this->admin->id);

    themeAssertRejected(fn () => app(RollbackPortalTheme::class)->handle('zz-uji', $this->admin), 'no_previous');
});

it('AC-11: refuses a rollback without a previous version, or with an invalid one, and changes nothing', function () {
    themeInstalledWrite('zz-tanpa-lama', themeBaseManifest());
    $invalid = [
        'broken json' => '{"schema_version":',
        'unsupported schema' => json_encode([...themeBaseManifest(), 'slug' => 'zz-lama-rusak', 'schema_version' => 99]),
        'other slug in manifest' => json_encode([...themeBaseManifest(), 'slug' => 'zz-bukan-aku']),
        'core too old' => json_encode([...themeBaseManifest(), 'slug' => 'zz-lama-rusak', 'min_core' => '999.0.0']),
    ];

    $before = themeInstalledTree();
    themeAssertRejected(fn () => app(RollbackPortalTheme::class)->handle('zz-tanpa-lama', $this->admin), 'no_previous');
    expect(themeInstalledTree())->toBe($before);

    foreach ($invalid as $label => $previous) {
        themeInstalledWrite('zz-lama-rusak', themeBaseManifest(), $previous);
        $before = themeInstalledTree();

        themeAssertRejected(fn () => app(RollbackPortalTheme::class)->handle('zz-lama-rusak', $this->admin));

        expect(themeInstalledTree())->toBe($before, $label);
    }

    expect(kontenActivities()->where('description', 'portal_theme_rolled_back'))->toHaveCount(0);
});

it('AC-11: removes the theme folder with its previous version and the install status, and logs it', function () {
    rrInstallTwoVersions('zz-hapus');
    rrInstallTwoVersions('zz-tetap');
    Setting::set('themes.install.zz-hapus', ['state' => 'done', 'token' => 'abc', 'message' => null, 'at' => now()->toIso8601String()]);
    Setting::set('themes.install.zz-tetap', ['state' => 'done', 'token' => 'abc', 'message' => null, 'at' => now()->toIso8601String()]);
    Setting::set('theme.template', 'zz-hapus');
    $keep = themeInstalledFile('zz-tetap');

    app(RemovePortalTheme::class)->handle('zz-hapus', $this->admin);

    $log = kontenActivities()->where('description', 'portal_theme_removed');

    expect(is_dir(themeInstalledSandbox().'/zz-hapus'))->toBeFalse()
        ->and(Setting::get('themes.install.zz-hapus'))->toBeNull()
        ->and(Setting::get('themes.install.zz-tetap'))->not->toBeNull()
        ->and(themeInstalledFile('zz-tetap'))->toBe($keep)
        ->and(Setting::get('theme.template'))->toBe('zz-hapus')
        ->and($log)->toHaveCount(1)
        ->and($log->first()->properties['slug'])->toBe('zz-hapus')
        ->and($log->first()->causer_id)->toBe($this->admin->id);
});

it('AC-11: rejects a traversal or malformed slug in rollback and remove without touching anything outside the theme folder', function () {
    $base = dirname(themeInstalledSandbox());
    mkdir("{$base}/victim", 0777);
    file_put_contents("{$base}/victim/theme.json", 'AMAN');
    file_put_contents("{$base}/victim/previous.json", 'AMAN');
    themeInstalledWrite('zz-sah', themeBaseManifest(), previous: json_encode([...themeBaseManifest(), 'slug' => 'zz-sah']));
    Setting::set('themes.install.victim', ['state' => 'done']);
    $before = themeInstalledBaseTree();

    foreach (['../victim', 'zz/../victim', '..', '', 'ZZ-BESAR', "zz-sah\n", '../themes/zz-sah', '/tmp/zz', '.'] as $slug) {
        foreach ([RollbackPortalTheme::class, RemovePortalTheme::class] as $action) {
            themeAssertRejected(fn () => app($action)->handle($slug, $this->admin), 'invalid_slug');

            expect(themeInstalledBaseTree())->toBe($before, "{$action} ".json_encode($slug));
        }
    }

    expect(Setting::get('themes.install.victim'))->not->toBeNull()
        ->and(kontenActivities()->where('description', 'portal_theme_removed'))->toHaveCount(0);
});

it('AC-11: refuses to remove or roll back an official theme', function () {
    $official = themeSandbox().'/centered/theme.json';
    $content = file_get_contents($official);

    foreach ([RollbackPortalTheme::class, RemovePortalTheme::class] as $action) {
        foreach (THEME_OFFICIAL as $slug) {
            themeAssertRejected(fn () => app($action)->handle($slug, $this->admin), 'official_slug');
        }
    }

    expect(file_get_contents($official))->toBe($content);
});

it('AC-3: only the holder of manage:themes can remove or roll back; other roles get an authorization error and nothing changes', function () {
    rrInstallTwoVersions();
    $before = themeInstalledTree();

    foreach (['admin-sekolah', 'admin-konten', 'bendahara', 'wali'] as $role) {
        foreach ([RollbackPortalTheme::class, RemovePortalTheme::class] as $action) {
            expect(fn () => app($action)->handle('zz-uji', kontenUser($role)))->toThrow(AuthorizationException::class);
        }
    }

    expect(themeInstalledTree())->toBe($before)
        ->and(kontenActivities()->whereIn('description', ['portal_theme_removed', 'portal_theme_rolled_back']))->toHaveCount(0);
});

it('AC-11: refuses a rollback or removal while the write lock of the slug is held', function () {
    rrInstallTwoVersions();
    $before = themeInstalledTree();
    $lock = Cache::lock('themes.write.zz-uji', 10);
    expect($lock->get())->toBeTrue();

    try {
        foreach ([RollbackPortalTheme::class, RemovePortalTheme::class] as $action) {
            themeAssertRejected(fn () => app($action)->handle('zz-uji', $this->admin), 'locked');
        }

        expect(themeInstalledTree())->toBe($before);
    } finally {
        $lock->release();
    }
});
