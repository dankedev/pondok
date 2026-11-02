<?php

use App\Actions\Konten\ListPortalThemes;

/*
| F102 AC-14 dan AC-15 (daftar). Kontrak yang diasumsikan: `ListPortalThemes::handle(): list<array{slug: string, name: string,
| description: string, valid: bool, error: ?string}>` membaca hanya `config('pondok.themes_path')/*`/theme.json (bawaan `resource_path('themes')`); nama dan deskripsi berasal
| dari manifest; tema yang gagal validasi tetap muncul dengan `valid = false` dan `error` berisi alasan (tanpa exception).
*/

/**
 * Entri daftar dari folder tema sementara test ini (`config('pondok.themes_path')`).
 *
 * @return array<string, array<string, mixed>> slug => entri
 */
function themeListed(): array
{
    themeSandbox();

    return collect(app(ListPortalThemes::class)->handle())
        ->keyBy('slug')
        ->all();
}

it('AC-14: lists exactly the four official themes (no klasik), valid, with name and description taken from the manifest', function () {
    $themes = themeListed();

    expect(array_keys($themes))->toEqualCanonicalizing(THEME_OFFICIAL);

    foreach ($themes as $slug => $theme) {
        $manifest = themeOfficialManifest($slug);

        expect($theme['valid'])->toBeTrue($slug)
            ->and($theme['error'])->toBeNull($slug)
            ->and($theme['name'])->toBe($manifest['name'])
            ->and($theme['description'])->toBe($manifest['description']);
    }
});

it('AC-15: keeps a theme with a broken manifest in the list, disabled with a reason, without failing the others', function () {
    $base = themeBaseManifest();
    $good = themeInstall($base);
    $broken = [
        'json rusak' => themeInstall('{"schema_version":'),
        'schema 99' => themeInstall([...$base, 'schema_version' => 99]),
        'kunci tak dikenal' => themeInstall([...$base, 'assets' => ['css' => ['x.css']]]),
        'min_core terlalu baru' => themeInstall([...$base, 'min_core' => '999.0.0']),
    ];

    $entries = collect(app(ListPortalThemes::class)->handle())->keyBy('slug');

    expect($entries[$good]['valid'])->toBeTrue();

    foreach ($broken as $label => $slug) {
        expect($entries->has($slug))->toBeTrue($label)
            ->and($entries[$slug]['valid'])->toBeFalse($label)
            ->and($entries[$slug]['error'])->toBeString()->not->toBe('', $label);
    }
});

/*
| F103 AC-2. `ListPortalThemes` ikut memuat tema terpasang (`config('pondok.installed_themes_path')/<slug>/theme.json`). Setiap entri
| mendapat `source` (`official` | `installed`) dan `version` (dari manifest; null bila tak valid). Slug terpasang yang sama dengan tema
| resmi dilewati (resmi menang). Hanya folder bernama slug sah (`^[a-z0-9-]{2,40}$`) yang terdaftar; `_staging` dan lainnya tidak.
*/

it('F103 AC-2: marks the four official themes as official and gives every valid entry its version', function () {
    themeInstalledSandbox();

    foreach (themeListed() as $slug => $theme) {
        expect($theme['source'])->toBe('official', $slug)
            ->and($theme['version'])->toBe(themeOfficialManifest($slug)['version'], $slug);
    }
});

it('F103 AC-2: lists installed themes next to the official ones, with source installed and the manifest version', function () {
    themeSandbox();
    themeInstalledSandbox();
    themeInstalledWrite('zz-terpasang', [...themeBaseManifest(), 'name' => 'Tema terpasang uji', 'version' => '2.3.4']);

    $themes = collect(app(ListPortalThemes::class)->handle())->keyBy('slug');

    expect($themes['zz-terpasang'])->toMatchArray(['source' => 'installed', 'version' => '2.3.4', 'name' => 'Tema terpasang uji', 'valid' => true, 'error' => null])
        ->and($themes->keys()->all())->toContain(...THEME_OFFICIAL)
        ->and(collect(app(ListPortalThemes::class)->handle())->first()['slug'])->toBe('default');
});

it('F103 AC-2: keeps a broken installed theme in the list as invalid with a reason', function () {
    themeSandbox();
    themeInstalledSandbox();
    themeInstalledWrite('zz-rusak', '{"schema_version":');
    themeInstalledWrite('zz-baru', [...themeBaseManifest(), 'min_core' => '999.0.0']);

    $themes = collect(app(ListPortalThemes::class)->handle())->keyBy('slug');

    foreach (['zz-rusak', 'zz-baru'] as $slug) {
        expect($themes[$slug]['source'])->toBe('installed')
            ->and($themes[$slug]['valid'])->toBeFalse($slug)
            ->and($themes[$slug]['error'])->toBeString()->not->toBe('', $slug);
    }
});

it('F103 AC-2: lets the official theme win over an installed folder with the same slug', function () {
    themeSandbox();
    themeInstalledSandbox();
    themeInstalledWrite('centered', [...themeBaseManifest(), 'name' => 'Penyamar', 'version' => '9.9.9']);

    $matches = collect(app(ListPortalThemes::class)->handle())->where('slug', 'centered');

    expect($matches)->toHaveCount(1)
        ->and($matches->first())->toMatchArray(['source' => 'official', 'name' => themeOfficialManifest('centered')['name']]);
});

it('F103 AC-2: never lists the staging folder, folders with a non-slug name, or loose files', function () {
    themeSandbox();
    $root = themeInstalledSandbox();
    themeInstalledWrite('zz-sah', themeBaseManifest());

    foreach (['_staging/zz-sedang-dipasang', 'Bad_Name', 'UPPER', 'x', '.tersembunyi', str_repeat('a', 41)] as $folder) {
        mkdir("{$root}/{$folder}", 0777, true);
        file_put_contents("{$root}/{$folder}/theme.json", json_encode([...themeBaseManifest(), 'slug' => basename($folder)]));
    }

    file_put_contents("{$root}/lepas.json", '{}');

    $installed = collect(app(ListPortalThemes::class)->handle())->where('source', 'installed')->pluck('slug')->all();

    expect($installed)->toBe(['zz-sah']);
});
