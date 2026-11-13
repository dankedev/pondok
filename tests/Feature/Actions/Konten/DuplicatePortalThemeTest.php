<?php

use App\Actions\Konten\DuplicatePortalTheme;
use App\Actions\Konten\ListPortalThemes;
use Illuminate\Auth\Access\AuthorizationException;

/*
| F111 PR2, AC-2 dan AC-3 (rencana F110). Kontrak yang diasumsikan:
| `DuplicatePortalTheme::handle(string $sourceSlug, array $input, User $actor): string` mengembalikan slug baru.
| `$input` hanya boleh berisi `name` (wajib, 1-60 karakter); slug = `lokal-` + `Str::slug(name)` (sisa maksimal 34 karakter).
| Hasil: tema terpasang biasa (`<slug>/theme.json`, `assets/<hash12>/...`), versi `1.0.0`, event log `duplicated` dengan pelaku.
| Penolakan = `InvalidArgumentException` berpesan Indonesia; folder terpasang tidak berubah dan tidak ada `_staging` tersisa.
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    $this->admin = kontenUser('super-admin');
});

function dpRejected(string $source, array $input, ?string $reason = null): void
{
    $before = themeInstalledTree();

    themeAssertRejected(fn () => app(DuplicatePortalTheme::class)->handle($source, $input, test()->admin), $reason);

    expect(themeInstalledTree())->toBe($before, 'folder terpasang tidak berubah')
        ->and(glob(themeInstalledSandbox().'/_staging/*') ?: [])->toBe([], 'tidak ada _staging tersisa');
}

it('F111 AC-2: duplicates an installed theme with assets into lokal-<name> with rescoped css and byte-identical fonts', function () {
    $pkg = themePkg(['slug' => 'zz-sumber']);
    themePkgPlace($pkg);
    $before = themeInstalledTree();

    $slug = app(DuplicatePortalTheme::class)->handle('zz-sumber', ['name' => 'Cahaya Saya'], $this->admin);

    $manifest = json_decode((string) themeInstalledFile($slug), true);
    $hash = substr(hash('sha256', (string) themeInstalledFile($slug)), 0, 12);
    $dir = themeInstalledSandbox()."/{$slug}/assets/{$hash}";
    $css = (string) file_get_contents("{$dir}/theme.css");
    $expectedCss = str_replace(themePkgScope('zz-sumber'), themePkgScope($slug), $pkg['files']['theme.css']);

    expect($slug)->toBe('lokal-cahaya-saya')
        ->and($manifest)->toMatchArray(['slug' => $slug, 'name' => 'Cahaya Saya', 'version' => '1.0.0'])
        ->and($css)->toBe($expectedCss)
        ->and($css)->not->toContain('data-pt-theme="zz-sumber"')
        ->and((string) file_get_contents("{$dir}/".F105_FONT_PATH))->toBe($pkg['files'][F105_FONT_PATH])
        ->and($manifest['assets']['css'])->toMatchArray(['sha256' => hash('sha256', $expectedCss), 'size' => strlen($expectedCss)])
        ->and($manifest['assets']['fonts'][0])->toMatchArray(['sha256' => hash('sha256', $pkg['files'][F105_FONT_PATH]), 'size' => strlen($pkg['files'][F105_FONT_PATH])])
        ->and($manifest['assets']['contrast'])->toBe($pkg['manifest']['assets']['contrast'])
        ->and($manifest['home'])->toBe($pkg['manifest']['home'])
        ->and(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', $slug))->toMatchArray(['source' => 'local', 'valid' => true, 'version' => '1.0.0'])
        ->and(array_intersect_key(themeInstalledTree(), $before))->toBe($before, 'tema sumber tetap byte-identik')
        ->and(glob(themeInstalledSandbox().'/_staging/*') ?: [])->toBe([]);
});

it('F111 AC-2: duplicates an official theme and a theme without assets as theme.json only', function (string $source) {
    if ($source === 'zz-polos') {
        themeInstalledWrite('zz-polos', themeBaseManifest());
    }

    $slug = app(DuplicatePortalTheme::class)->handle($source, ['name' => 'Salinan Polos'], $this->admin);
    $manifest = json_decode((string) themeInstalledFile($slug), true);
    $original = json_decode((string) file_get_contents(
        $source === 'default' ? resource_path('themes/default/theme.json') : themeInstalledSandbox().'/zz-polos/theme.json'
    ), true);

    expect($slug)->toBe('lokal-salinan-polos')
        ->and($manifest)->toBe([...$original, 'slug' => $slug, 'name' => 'Salinan Polos', 'version' => '1.0.0'])
        ->and(array_keys(array_filter(themeInstalledTree(), fn (string $_, string $path): bool => str_starts_with($path, "{$slug}/") || $path === $slug, ARRAY_FILTER_USE_BOTH)))
        ->toBe([$slug, "{$slug}/theme.json"])
        ->and(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', $slug))->toMatchArray(['source' => 'local', 'valid' => true]);
})->with(['official default' => 'default', 'installed without assets' => 'zz-polos']);

it('F111 AC-2: logs a duplicated event with the actor, and refuses an actor without manage:themes', function () {
    themePkgPlace(themePkg(['slug' => 'zz-sumber']));

    $slug = app(DuplicatePortalTheme::class)->handle('zz-sumber', ['name' => 'Catatan'], $this->admin);
    $log = kontenActivities()->where('event', 'duplicated');

    expect($log)->toHaveCount(1)
        ->and($log->first()->properties['slug'])->toBe($slug)
        ->and($log->first()->causer_id)->toBe($this->admin->id);

    $before = themeInstalledTree();

    expect(fn () => app(DuplicatePortalTheme::class)->handle('zz-sumber', ['name' => 'Ditolak'], kontenUser('bendahara')))->toThrow(AuthorizationException::class)
        ->and(themeInstalledTree())->toBe($before);
});

it('F111 AC-3: refuses an existing target slug, leaving everything untouched', function () {
    themePkgPlace(themePkg(['slug' => 'zz-sumber']));
    app(DuplicatePortalTheme::class)->handle('zz-sumber', ['name' => 'Kembar'], $this->admin);

    dpRejected('zz-sumber', ['name' => 'Kembar']);
    dpRejected('zz-sumber', ['name' => 'KEMBAR!']);
});

it('F111 AC-3: refuses an invalid or missing source', function (string $source) {
    themeInstalledWrite('zz-rusak', '{"schema_version":');

    dpRejected($source, ['name' => 'Tidak Jadi']);
})->with(['broken json' => 'zz-rusak', 'not installed' => 'zz-tidak-ada', 'path traversal' => '../zz-rusak', 'staging' => '_staging']);

it('F111 AC-3: refuses an empty, blank, unsluggable or too long name', function (mixed $name) {
    themePkgPlace(themePkg(['slug' => 'zz-sumber']));

    dpRejected('zz-sumber', ['name' => $name]);
})->with([
    'empty' => '',
    'blank' => '   ',
    'only symbols' => '!!!',
    'null' => null,
    'array' => [['x']],
    'name over 60' => str_repeat('a', 61),
    'slug remainder over 34' => str_repeat('a', 35),
]);

it('F111 AC-3: refuses a missing name and unknown input keys', function (array $input) {
    themePkgPlace(themePkg(['slug' => 'zz-sumber']));

    dpRejected('zz-sumber', $input);
})->with([
    'no name' => [[]],
    'unknown key' => [['name' => 'Baik', 'slug' => 'lokal-lain']],
    'css key' => [['name' => 'Baik', 'css' => 'body{}']],
]);
