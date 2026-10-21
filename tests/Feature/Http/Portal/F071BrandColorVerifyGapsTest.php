<?php

use App\Actions\Konten\BuildColorScale;
use App\Actions\Konten\GetPortalTheme;
use App\Filament\Pages\Site\Theme;
use Livewire\Livewire;

/*
| F071 VERIFY: celah di luar test RED. Aksen yang membuat primary ditolak, nilai pratinjau aneh, ujung skala,
| properti pada sampel acak deterministik, bawaan tanpa token merek, dan sisa kelas navy tetap di seluruh views.
*/

function brandContrast(string $a, string $b): float
{
    return BuildColorScale::contrast($a, $b);
}

it('rejects the primary colour when the accent scale breaks the contrast rule', function () {
    $badAccent = [...BuildColorScale::DEFAULT_ACCENT, 400 => '#222222', 500 => '#222222'];

    expect(GetPortalTheme::brandTokens('#15803d', $badAccent))->toBeNull()
        ->and(GetPortalTheme::brandTokens('#15803d', BuildColorScale::DEFAULT_ACCENT))->not->toBeNull();
});

it('previews odd primary and accent values without errors and without raw values in style attributes', function () {
    $this->f = kontenBoot();
    $this->actingAs(kontenUser('admin-konten'));
    $component = Livewire::test(Theme::class);

    foreach (['#FFF', '#000000;}', '#15803d;background:url(x)', '<script>', 'red', ''] as $value) {
        $component->fillForm(['primary' => $value])->assertHasNoErrors();
        expect($component->html())->not->toContain('Contoh tombol', "primary {$value}");
    }

    foreach (['#FFF', '#000000;}"><x>', 'red', ''] as $accent) {
        $html = $component->fillForm(['primary' => '#15803d', 'accent' => $accent])->assertHasNoErrors()->html();

        expect($html)->toContain('Contoh tombol')
            ->and($html)->not->toContain('<x>')
            ->and(preg_match_all('/style="([^"]*)"/', $html, $styles) ? implode("\n", $styles[1]) : '')->not->toContain('url(', "accent {$accent}");
    }

    $component->fillForm(['primary' => '#15803D', 'accent' => '#FFD452'])->assertHasNoErrors()->assertSee('Contoh tombol')->assertSee('#15803d');
});

it('brandTokens ignores array and non-string input without error', function () {
    expect(GetPortalTheme::brandTokens(['#15803d']))->toBeNull()
        ->and(GetPortalTheme::brandTokens(123456))->toBeNull()
        ->and(GetPortalTheme::brandTokens("#15803d\n"))->toBeNull()
        ->and(GetPortalTheme::brandTokens('#15803D'))->not->toBeNull();
});

it('keeps hover distinct from the brand and readable at both ends of the scale', function () {
    foreach (['#ffffff', '#000000'] as $hex) {
        $tokens = GetPortalTheme::brandTokens($hex);

        expect($tokens)->not->toBeNull()
            ->and($tokens['brand_hover'])->not->toBe($tokens['brand'], $hex)
            ->and(brandContrast($tokens['brand_hover'], $tokens['on_brand']))->toBeGreaterThanOrEqual(4.5)
            ->and(brandContrast($tokens['brand'], $tokens['on_brand']))->toBeGreaterThanOrEqual(4.5);
    }
});

it('holds the contrast and scale-membership guarantees for 200 deterministic colours', function () {
    mt_srand(71);
    $builder = app(BuildColorScale::class);
    $checked = 0;

    for ($i = 0; $i < 200; $i++) {
        $hex = sprintf('#%06x', mt_rand(0, 0xFFFFFF));
        $scale = $builder->handle($hex, 'primary');

        if (BuildColorScale::contrastFailure($scale, BuildColorScale::DEFAULT_ACCENT) !== null) {
            continue;
        }

        $checked++;
        $t = BuildColorScale::brandColors($hex, $scale);

        expect($t['brand'])->toBeIn($scale, $hex)
            ->and($t['brand_hover'])->toBeIn($scale, $hex)
            ->and($t['brand_text'])->toBeIn($scale, $hex)
            ->and($t['on_brand'])->toBeIn(['#ffffff', $scale[950]], $hex)
            ->and(brandContrast($t['brand'], $t['on_brand']))->toBeGreaterThanOrEqual(4.5, "brand {$hex}")
            ->and(brandContrast($t['brand_hover'], $t['on_brand']))->toBeGreaterThanOrEqual(4.5, "hover {$hex}")
            ->and(brandContrast($t['brand_text'], '#ffffff'))->toBeGreaterThanOrEqual(4.5, "text {$hex}");
    }

    expect($checked)->toBeGreaterThan(100);
});

it('writes no brand token and no style for the default theme, and the home page omits them', function () {
    portalBoot();
    themeSet(['accent' => '#ffd452', 'radius' => 'large']);

    expect(app(GetPortalTheme::class)->handle()['css'])->not->toContain('--color-brand');

    expect($this->get('/')->assertOk()->getContent())->not->toContain('--color-brand:');
});

it('leaves no fixed navy fill, hover or accent class anywhere in resources/views except errors', function () {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php') || str_contains($file->getPathname(), '/views/errors/')) {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        foreach (['bg-primary-900', 'hover:bg-primary-800', 'accent-primary-900'] as $class) {
            expect(preg_match('/(?<![\w:-])'.preg_quote($class, '/').'(?![\w-])/', $source))->toBe(0, $file->getPathname()." memuat {$class}");
        }
    }
});
