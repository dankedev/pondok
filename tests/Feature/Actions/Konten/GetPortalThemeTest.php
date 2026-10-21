<?php

use App\Actions\Konten\GetPortalTheme;

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
    expect(themeResolve())->toBe(['css' => null, 'font_aliases' => null]);
});

it('AC-1: treats explicit default values as no theme', function () {
    themeSet(['primary' => null, 'accent' => null, 'font' => 'jakarta', 'radius' => 'medium']);

    expect(themeResolve())->toBe(['css' => null, 'font_aliases' => null]);
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

    expect(themeResolve())->toBe(['css' => null, 'font_aliases' => null]);
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

    expect(themeResolve())->toBe(['css' => null, 'font_aliases' => null]);
});
