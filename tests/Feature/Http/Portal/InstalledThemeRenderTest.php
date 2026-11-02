<?php

use App\Actions\Konten\GetPortalTheme;
use App\Actions\Konten\RemovePortalTheme;
use App\Actions\Konten\ResolveActiveTheme;
use App\Actions\Konten\RollbackPortalTheme;
use App\Actions\Konten\UpdatePortalTheme;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;

/*
| F103 AC-2 dan AC-11 (sisi render). Tema terpasang (`pondok.installed_themes_path`) dirender persis seperti tema bawaan:
| `ResolveActiveTheme`, `GetPortalTheme::template()` dan `UpdatePortalTheme` mengenalinya lewat resolver path yang sama.
| Tema uji hanya memakai node `quote`, sehingga blok hero tidak tampil; tanpa tema (chrome inti) kedua blok tampil.
*/

beforeEach(function () {
    portalBoot();
    $this->seed(RolePermissionSeeder::class);
    themeSandbox();
    themeInstalledSandbox();
    blkSet([
        blk('hero', ['eyebrow' => 'Label hero', 'title' => 'Judul hero terpasang', 'text' => 'Teks hero', 'image' => 'portal/blocks/hero.jpg', 'image_alt' => 'Gedung', 'primary' => navItem('posts', 'Tombol hero')]),
        blk('quote', ['text' => 'Kutipan terpasang uji', 'source' => 'Sumber uji']),
    ]);
});

function icInstall(string $slug = 'zz-terpasang', array $manifest = []): string
{
    themeInstalledWrite($slug, [
        ...themeBaseManifest(),
        'header' => null,
        'footer' => null,
        'home' => ['layout' => [['component' => 'quote', 'style' => ['tone' => 'auto']]]],
        ...$manifest,
    ]);

    return $slug;
}

function icActivate(string $slug): void
{
    Setting::set('theme.template', $slug);
    app()->forgetScopedInstances();
}

it('F103 AC-2: renders an installed theme on the portal home and resolves it as the active theme', function () {
    $slug = icInstall();
    icActivate($slug);

    $html = blkHome();

    expect(app(ResolveActiveTheme::class)->handle()['slug'])->toBe($slug)
        ->and($html)->toContain('Kutipan terpasang uji')
        ->and($html)->not->toContain('Judul hero terpasang');
});

it('F103 AC-2: shows both blocks with the core chrome when no theme is active', function () {
    icInstall();

    $html = blkHome();

    expect($html)->toContain('Kutipan terpasang uji')->toContain('Judul hero terpasang');
});

it('F103 AC-2: GetPortalTheme::template recognises an installed theme and falls back to default when the folder is absent', function () {
    $slug = icInstall();
    icActivate($slug);

    expect(app(GetPortalTheme::class)->template())->toBe($slug);

    icActivate('zz-tidak-ada');

    expect(app(GetPortalTheme::class)->template())->toBe('default');
});

it('F103 AC-2: lets an installed theme be chosen through UpdatePortalTheme, and rejects a broken installed one', function () {
    $slug = icInstall();
    $broken = icInstall('zz-rusak', ['schema_version' => 99]);
    $admin = kontenUser('super-admin');

    app(UpdatePortalTheme::class)->handle(['template' => $slug], $admin);

    expect(Setting::get('theme.template'))->toBe($slug);

    kontenAssertRejected(fn () => app(UpdatePortalTheme::class)->handle(['template' => $broken], $admin), 'theme_option_invalid', 'template');

    expect(Setting::get('theme.template'))->toBe($slug);
});

it('F103 AC-2: falls back to the core chrome with a single warning when the active installed theme is broken', function () {
    $slug = icInstall('zz-rusak-aktif', ['schema_version' => 99]);
    icActivate($slug);
    Log::spy();

    $first = blkHome();
    $second = blkHome();

    expect($first)->toContain('Kutipan terpasang uji')->toContain('Judul hero terpasang')
        ->and($second)->toContain('Judul hero terpasang');

    Log::shouldHaveReceived('warning')->once();
});

it('F103 AC-11: a removed or rolled back active theme leaves the portal at 200 with the core chrome and keeps theme.template', function () {
    $slug = icInstall();
    icActivate($slug);
    expect(blkHome())->not->toContain('Judul hero terpasang');

    app(RemovePortalTheme::class)->handle($slug, kontenUser('super-admin'));
    app()->forgetScopedInstances();

    expect(blkHome())->toContain('Judul hero terpasang')->toContain('Kutipan terpasang uji')
        ->and(Setting::get('theme.template'))->toBe($slug);

    // Pasang ulang berkas yang sama: tema aktif lagi tanpa memilih ulang.
    icInstall($slug);
    app()->forgetScopedInstances();

    expect(blkHome())->not->toContain('Judul hero terpasang');
});

it('F103 AC-11: a refused rollback (invalid previous.json) leaves the active theme rendering', function () {
    $slug = icInstall();
    themeInstalledWrite($slug, [...themeBaseManifest(), 'version' => '2.0.0', 'header' => null, 'footer' => null, 'home' => ['layout' => [['component' => 'quote']]]], previous: json_encode([...themeBaseManifest(), 'slug' => $slug, 'schema_version' => 99]));
    icActivate($slug);
    expect(blkHome())->not->toContain('Judul hero terpasang');

    // `previous.json` tak valid: rollback ditolak dan tema aktif tetap utuh.
    themeAssertRejected(fn () => app(RollbackPortalTheme::class)->handle($slug, kontenUser('super-admin')));
    app()->forgetScopedInstances();

    expect(blkHome())->not->toContain('Judul hero terpasang');
});
