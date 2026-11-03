<?php

use App\Actions\Konten\BuildColorScale;
use App\Actions\Konten\GetPortalTheme;
use App\Actions\Konten\ThemePalette;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\View;

/*
| F070 AC-1, AC-9, AC-10, AC-12 (bagian Action). Kontrak: `GetPortalTheme::handle(): array{css: ?string, font_aliases: ?list<string>}`.
| `css` = `:root{...}` berisi hanya token hasil `BuildColorScale` dan peta font/radius server; semua bawaan = null.
| Font non-bawaan dipakai hanya bila semua aliasnya ada di `families` `public_path('build/fonts-manifest.json')`.
| Nilai DB yang rusak diabaikan per key tanpa exception.
*/

beforeEach(function () {
    $this->f = kontenBoot();
    themeBuild();
});

function themeResolve(): array
{
    return app(GetPortalTheme::class)->handle();
}

it('AC-1: returns no css and no font aliases when no theme key is set', function () {
    expect(themeResolve())->toBe(['css' => null, 'font_aliases' => null, 'template' => 'default']);
});

it('AC-1: treats explicit default values as no theme', function () {
    themeSet(['primary' => null, 'accent' => null, 'font' => 'jakarta', 'radius' => 'medium']);

    expect(themeResolve())->toBe(['css' => null, 'font_aliases' => null, 'template' => 'default']);
});

it('AC-9: writes every token for a full theme and nothing else', function () {
    themeSet(['primary' => '#1f2a5e', 'accent' => '#ffd452', 'font' => 'nunito', 'radius' => 'large']);

    $theme = themeResolve();
    $css = $theme['css'];

    expect($css)->toStartWith(':root{')
        ->and(substr_count($css, '--color-primary-'))->toBe(11)
        ->and(substr_count($css, '--color-accent-'))->toBe(11)
        ->and($css)->toContain("--font-sans: 'Nunito'")->toContain('--radius-xl: 1.25rem')
        ->not->toContain('--font-heading')->not->toContain('url(')
        ->and($css)->toMatch('/^:root\{[^<>{}\\\\@]*\}$/')
        ->and($theme['font_aliases'])->toBe(['nunito', 'noto-naskh-arabic']);
});

it('AC-9: emits only the primary scale when only the primary colour is set', function () {
    themeSet(['primary' => '#1f2a5e']);

    $theme = themeResolve();

    expect($theme['css'])->toContain('--color-primary-950:')
        ->not->toContain('--color-accent-')->not->toContain('--font-')->not->toContain('--radius-')
        ->and($theme['font_aliases'])->toBeNull();
});

it('AC-9: sets the heading font only for Lora and reports its aliases', function () {
    themeSet(['font' => 'lora']);

    $theme = themeResolve();

    expect($theme['css'])->toContain('--font-heading:')->toContain('Lora')->not->toContain('--font-sans')
        ->and($theme['font_aliases'])->toBe(['plus-jakarta-sans', 'lora', 'noto-naskh-arabic']);
});

it('AC-9: maps the radius options on the server', function () {
    themeSet(['radius' => 'small']);

    expect(themeResolve()['css'])->toMatch('/--radius-lg:\s*0?\.25rem/')->toMatch('/--radius-xl:\s*0?\.375rem/');

    themeSet(['radius' => 'large']);

    expect(themeResolve()['css'])->toMatch('/--radius-lg:\s*0?\.75rem/')->toMatch('/--radius-xl:\s*1\.25rem/');
});

it('AC-10: ignores a corrupt stored value', function (string $key, mixed $value) {
    themeSet([$key => $value]);

    expect(themeResolve())->toBe(['css' => null, 'font_aliases' => null, 'template' => 'default']);
})->with([
    'css injection in primary' => ['primary', '#000000;}</style><script>alert(1)</script>'],
    'trailing newline' => ['primary', "#1f2a5e\n"],
    'array accent' => ['accent', ['x']],
    'unknown font' => ['font', 'x'],
    'radius with css' => ['radius', 'large;}'],
]);

it('AC-10: drops only the corrupt key and keeps the valid ones', function () {
    themeSet(['primary' => '#1f2a5e', 'accent' => '#ffd452;}', 'radius' => 'large']);

    $css = themeResolve()['css'];

    expect($css)->toContain('--color-primary-900:')->toContain('--radius-xl: 1.25rem')->not->toContain('--color-accent-');
});

it('AC-12: falls back to the default font when the manifest lacks an alias, the file or valid JSON', function (array|string|null $manifest) {
    themeBuild($manifest);
    themeSet(['primary' => '#1f2a5e', 'font' => 'nunito', 'radius' => 'large']);

    $theme = themeResolve();

    expect($theme['font_aliases'])->toBeNull()
        ->and($theme['css'])->not->toContain('--font-sans')->not->toContain('--font-heading')
        ->toContain('--color-primary-900:')->toContain('--radius-xl: 1.25rem');
})->with([
    'alias nunito missing' => [['plus-jakarta-sans', 'lora', 'noto-naskh-arabic']],
    'alias arabic missing' => [['plus-jakarta-sans', 'nunito']],
    'no manifest file' => [null],
    'broken json' => ['{bukan json'],
]);

it('AC-12: needs the Lora aliases too before using Lora', function () {
    themeBuild(['plus-jakarta-sans', 'noto-naskh-arabic']);
    themeSet(['font' => 'lora']);

    expect(themeResolve())->toBe(['css' => null, 'font_aliases' => null, 'template' => 'default']);
});

/*
| F072 AC-1, AC-4 (Action). Kontrak: `GetPortalTheme::template(): string`, `handle()['template']`, `DEFAULT_TEMPLATE`.
| F102 menghapus `TEMPLATES` dan `blockView` (daftar tema dari `ListPortalThemes`); pemetaan blok ke view dicakup golden
| `PortalThemeGoldenTest`. Test AC-5 lama (blockView) diganti test F102 AC-6.
*/

it('F072 AC-1: defaults to the default template', function () {
    $theme = themeResolve();

    expect(app(GetPortalTheme::class)->template())->toBe('default')
        ->and($theme['template'])->toBe('default')
        ->and($theme['css'])->toBeNull();
});

it('F072 AC-1: the template does not affect the generated css', function () {
    themeSet(['template' => 'centered']);

    expect(themeResolve())->toBe(['css' => null, 'font_aliases' => null, 'template' => 'centered']);
});

it('F072 AC-4: falls back to the default template for a corrupted stored value', function () {
    foreach (['../portal/home', "centered\n", ['centered'], 'tidak-ada'] as $value) {
        themeSet(['template' => $value]);

        expect(app(GetPortalTheme::class)->template())->toBe('default', json_encode($value))
            ->and(themeResolve()['template'])->toBe('default', json_encode($value));
    }
});

it('F102 AC-6: no longer carries the hard-coded preset list or the block view resolver', function () {
    expect(defined(GetPortalTheme::class.'::TEMPLATES'))->toBeFalse('TEMPLATES diganti ListPortalThemes')
        ->and(method_exists(GetPortalTheme::class, 'blockView'))->toBeFalse('blockView diganti renderer tema');
});

/*
| F071 AC-6, AC-7, AC-13 (Action). Kontrak: `static GetPortalTheme::brandTokens(mixed $hex, ?array $accentScale = null): ?array`
| (hasil `BuildColorScale::brandColors`, null bila bukan hex atau skala gagal kontras); `handle()` menulis `--color-brand`,
| `--color-brand-hover`, `--color-on-brand`, `--color-brand-text` hanya bila skala primary kustom bertahan.
*/

it('F071 AC-6: writes the four brand tokens next to the primary scale', function () {
    themeSet(['primary' => '#15803d']);

    $css = themeResolve()['css'];

    expect($css)->toContain('--color-brand: #15803d;')
        ->toContain('--color-on-brand: #ffffff;')
        ->toContain('--color-brand-text: #15803d;')
        ->toMatch('/--color-brand-hover: #[0-9a-f]{6};/')
        ->toMatch('/^:root\{[^<>{}\\\\@]*\}$/');
});

it('F071 AC-7: writes no brand token for the default theme, without a primary, or with a corrupt or rejected primary', function () {
    expect(themeResolve()['css'])->toBeNull();

    themeSet(['accent' => '#ffd452', 'font' => 'nunito', 'radius' => 'large']);

    expect(themeResolve()['css'])->not->toContain('--color-brand');

    themeSet(['primary' => '#000000;}']);

    expect(themeResolve()['css'])->not->toContain('--color-brand');

    themeSet(['primary' => '#15803d']);
    app()->bind(BuildColorScale::class, fn () => new class extends BuildColorScale
    {
        public function handle(string $hex, string $role): array
        {
            return $role === 'primary' ? [...THEME_DEFAULT_PRIMARY, 900 => '#777777'] : THEME_DEFAULT_ACCENT;
        }
    });

    expect(themeResolve()['css'])->not->toContain('--color-brand')->not->toContain('--color-primary-');
});

it('F071 AC-13: brandTokens returns null for anything that is not a usable hex colour', function () {
    foreach (['red', null, '#fff', "#15803d\n", ['#15803d']] as $value) {
        expect(GetPortalTheme::brandTokens($value))->toBeNull(json_encode($value));
    }

    expect(GetPortalTheme::brandTokens('#15803D'))->toMatchArray(['brand' => '#15803d', 'on_brand' => '#ffffff']);
});

it('F071 AC-13: brandTokens returns null when the primary scale fails the contrast rule', function () {
    app()->bind(BuildColorScale::class, fn () => new class extends BuildColorScale
    {
        public function handle(string $hex, string $role): array
        {
            return [...THEME_DEFAULT_PRIMARY, 900 => '#777777'];
        }
    });

    expect(GetPortalTheme::brandTokens('#15803d'))->toBeNull();
});

/*
| F104 AC-1, AC-4, AC-5, AC-6. Tema aktif (`ResolveActiveTheme`) menjadi nilai bawaan di bawah pengaturan admin
| `theme.primary`/`theme.accent`/`theme.font`. Tema uji ditulis lewat `themeInstall()` dan diaktifkan lewat `themeActivate()`.
*/

/**
 * @param  array<string, mixed>  $extra  kunci warna/font tema
 */
function themeColoured(array $extra, string $minCore = '1.1.0'): string
{
    $slug = themeInstall([...themeBaseManifest(), 'min_core' => $minCore, ...$extra]);
    themeActivate($slug);

    return $slug;
}

/**
 * @return array{css: ?string, font_aliases: ?list<string>}
 */
function themeLook(): array
{
    return Arr::only(themeResolve(), ['css', 'font_aliases']);
}

it('F104 AC-1: gives the same output as no theme for a v1 theme and the official themes across admin settings', function () {
    $combos = [
        'kosong' => [],
        'primer' => ['primary' => '#1f2a5e'],
        'aksen' => ['accent' => '#f8b800'],
        'font' => ['font' => 'nunito'],
        'semua' => ['primary' => '#1f2a5e', 'accent' => '#ffd452', 'font' => 'lora', 'radius' => 'large'],
    ];

    foreach ($combos as $label => $values) {
        themeSet(['primary' => null, 'accent' => null, 'font' => null, 'radius' => null, ...$values]);
        themeActivate(null);
        $baseline = themeLook();

        foreach ([themeInstall(themeBaseManifest()), ...THEME_OFFICIAL] as $slug) {
            themeActivate($slug);

            expect(themeLook())->toBe($baseline, "{$label} / {$slug}");
        }
    }
});

it('F104 AC-4: palette hijau without admin settings writes the primary scale and brand tokens only', function () {
    $hex = ThemePalette::PALETTES['hijau'];
    $scale = app(BuildColorScale::class)->handle($hex, 'primary');
    $brand = GetPortalTheme::brandTokens($hex);
    themeColoured(['palette' => 'hijau']);

    $css = themeLook()['css'];

    expect($css)->toStartWith(':root{')
        ->and(substr_count($css, '--color-primary-'))->toBe(11)
        ->and($css)->toContain("--color-primary-600: {$scale[600]};")->toContain("--color-primary-950: {$scale[950]};")
        ->toContain("--color-brand: {$brand['brand']};")->toContain("--color-on-brand: {$brand['on_brand']};")
        ->toContain("--color-brand-text: {$brand['brand_text']};")->toContain("--color-brand-hover: {$brand['brand_hover']};")
        ->not->toContain('--color-accent-')->not->toContain('--color-gray-')->not->toContain('--font-')
        ->toMatch('/^:root\{[^<>{}\\\\@]*\}$/');
});

it('F104 AC-4: palette navy and surface putih add nothing', function () {
    themeColoured(['palette' => 'navy', 'surface' => 'putih']);

    expect(themeLook())->toBe(['css' => null, 'font_aliases' => null]);
});

it('F104 AC-4: surface krem writes exactly the two gray declarations', function () {
    $surface = ThemePalette::SURFACES['krem'];
    themeColoured(['surface' => 'krem']);

    $css = themeLook()['css'];

    expect(substr_count($css, '--color-gray-'))->toBe(2)
        ->and($css)->toContain("--color-gray-50: {$surface['--color-gray-50']};")->toContain("--color-gray-100: {$surface['--color-gray-100']};")
        ->not->toContain('--color-primary-')->not->toContain('--color-white')
        ->toMatch('/^:root\{[^<>{}\\\\@]*\}$/');
});

it('F104 AC-4: font lora from the theme writes the heading font and its aliases', function () {
    themeColoured(['font' => 'lora']);

    $look = themeLook();

    expect($look['css'])->toContain("--font-heading: 'Lora'")
        ->and($look['font_aliases'])->toBe(['plus-jakarta-sans', 'lora', 'noto-naskh-arabic']);
});

it('F104 AC-4: combines palette, surface and font from one theme', function () {
    themeColoured(['palette' => 'hijau', 'surface' => 'krem', 'font' => 'nunito']);

    $look = themeLook();

    expect(substr_count($look['css'], '--color-primary-'))->toBe(11)
        ->and(substr_count($look['css'], '--color-gray-'))->toBe(2)
        ->and($look['css'])->toContain("--font-sans: 'Nunito'")
        ->and($look['font_aliases'])->toBe(['nunito', 'noto-naskh-arabic']);
});

it('F104 AC-5: admin primary, accent and font win over the theme', function () {
    $themeHex = ThemePalette::PALETTES['hijau'];
    $themeScale = app(BuildColorScale::class)->handle($themeHex, 'primary');
    $adminScale = app(BuildColorScale::class)->handle('#1f2a5e', 'primary');
    themeSet(['primary' => '#1f2a5e', 'accent' => '#f8b800', 'font' => 'nunito']);
    themeColoured(['palette' => 'hijau', 'font' => 'lora']);

    $look = themeLook();

    expect($look['css'])->toContain("--color-primary-600: {$adminScale[600]};")->not->toContain($themeScale[600])
        ->and(substr_count($look['css'], '--color-accent-'))->toBe(11)
        ->and($look['css'])->toContain("--font-sans: 'Nunito'")->not->toContain('--font-heading')
        ->and($look['font_aliases'])->toBe(['nunito', 'noto-naskh-arabic']);
});

it('F104 AC-5: an empty admin value follows the theme, a filled one overrides only itself', function () {
    $themeScale = app(BuildColorScale::class)->handle(ThemePalette::PALETTES['hijau'], 'primary');
    themeSet(['primary' => null, 'accent' => '#f8b800', 'font' => null]);
    themeColoured(['palette' => 'hijau', 'font' => 'lora']);

    $look = themeLook();

    expect($look['css'])->toContain("--color-primary-600: {$themeScale[600]};")->toContain("--font-heading: 'Lora'")
        ->and(substr_count($look['css'], '--color-accent-'))->toBe(11);
});

it('F104 AC-5: never takes the accent from the theme and survives an unreadable admin accent', function () {
    themeColoured(['palette' => 'hijau']);
    expect(themeLook()['css'])->not->toContain('--color-accent-');

    themeSet(['accent' => '#000000']);

    expect(fn () => themeLook())->not->toThrow(Throwable::class)
        ->and(themeLook()['css'])->toBeString()->toStartWith(':root{');
});

it('F104 AC-6: a missing, broken or too-new theme gives the output of no theme', function () {
    themeSet(['primary' => null, 'accent' => null, 'font' => null]);
    themeActivate(null);
    $baseline = themeLook();

    $tooNew = themeColoured(['palette' => 'hijau', 'surface' => 'krem', 'font' => 'lora'], '1.3.0');
    $broken = themeInstall('{ bukan json');
    $badValue = themeColoured(['palette' => '#2e7d32', 'font' => 'url(x)']);

    foreach (['zz-tidak-ada', $tooNew, $broken, $badValue] as $slug) {
        themeActivate($slug);

        expect(themeLook())->toBe($baseline, $slug);
    }
});

it('F104 AC-6: a theme font whose aliases are missing from the font manifest falls back to Jakarta', function () {
    themeBuild(['plus-jakarta-sans', 'noto-naskh-arabic']);
    themeColoured(['palette' => 'hijau', 'font' => 'lora']);

    $look = themeLook();

    expect($look['font_aliases'])->toBeNull()
        ->and($look['css'])->not->toContain('--font-heading')
        ->and(substr_count($look['css'], '--color-primary-'))->toBe(11, 'palet tema tetap berlaku');
});

it('F104 AC-6: an admin font missing from the manifest falls back to Jakarta even over a themed font', function () {
    themeBuild(['plus-jakarta-sans', 'lora', 'noto-naskh-arabic']);
    themeSet(['font' => 'nunito']);
    themeColoured(['font' => 'lora']);

    $look = themeLook();

    expect($look['font_aliases'])->toBeNull()->and($look['css'])->toBeNull();
});
