<?php

use App\Actions\Konten\BuildColorScale;
use App\Actions\Konten\GetPortalTheme;
use App\Actions\Konten\ThemePalette;

/*
| F104 AC-3 dan AC-4 (bentuk peta). Kontrak: `ThemePalette::PALETTES` (nama => hex primer `#rrggbb`) dan
| `ThemePalette::SURFACES` (nama => ['--color-gray-50' => hex, '--color-gray-100' => hex]). Test membaca hex dari peta,
| tidak bergantung pada nilai pastinya. Skala slate bawaan (`--color-gray-*` di app.css = slate Tailwind v4) ditulis di sini.
*/

const THEME_SLATE = ['500' => '#62748e', '600' => '#45556c', '700' => '#314158', '900' => '#0f172b'];

it('F104 AC-4: keeps the palette and surface maps to the agreed names and shapes', function () {
    expect(array_keys(ThemePalette::PALETTES))->toBe(['hijau'])
        ->and(array_keys(ThemePalette::SURFACES))->toBe(['krem']);

    foreach (ThemePalette::PALETTES as $name => $hex) {
        expect($hex)->toMatch('/^#[0-9a-f]{6}\z/', "palet {$name}");
    }

    foreach (ThemePalette::SURFACES as $name => $tokens) {
        expect(array_keys($tokens))->toBe(['--color-gray-50', '--color-gray-100'], "permukaan {$name}");

        foreach ($tokens as $token => $hex) {
            expect($token)->toMatch('/^--color-[a-z0-9-]+\z/')->and($hex)->toMatch('/^#[0-9a-f]{6}\z/', "{$name} {$token}");
        }
    }
});

it('F104 AC-3: every palette scale passes the contrast rule with the default accent and yields brand tokens', function () {
    foreach (ThemePalette::PALETTES as $name => $hex) {
        $scale = app(BuildColorScale::class)->handle($hex, 'primary');

        expect(BuildColorScale::contrastFailure($scale, BuildColorScale::DEFAULT_ACCENT))->toBeNull("palet {$name}")
            ->and(GetPortalTheme::brandTokens($hex))->not->toBeNull("palet {$name}: brandTokens");
    }
});

it('F104 AC-3: each surface keeps text readable on gray-50 and gray-100', function () {
    // Baseline: gray-500 di atas slate-100 bawaan hanya 4,35:1, jadi di gray-100 krem ia tidak boleh lebih buruk dari itu.
    $baseline = BuildColorScale::contrast(THEME_SLATE['500'], '#f1f5f9');

    foreach (ThemePalette::SURFACES as $name => $tokens) {
        foreach ($tokens as $token => $surface) {
            foreach (THEME_SLATE as $step => $text) {
                $min = $step === '500' && $token === '--color-gray-100' ? $baseline - 0.0001 : BuildColorScale::MIN_CONTRAST;

                expect(BuildColorScale::contrast($text, $surface))->toBeGreaterThanOrEqual($min, "{$name} {$token} vs slate-{$step}");
            }

            foreach (ThemePalette::PALETTES as $palette => $hex) {
                $scale = app(BuildColorScale::class)->handle($hex, 'primary');
                $brandText = GetPortalTheme::brandTokens($hex)['brand_text'];

                expect(BuildColorScale::contrast($brandText, $surface))->toBeGreaterThanOrEqual(BuildColorScale::MIN_CONTRAST, "{$name} {$token} vs brand_text {$palette}")
                    ->and(BuildColorScale::contrast($scale[700], $surface))->toBeGreaterThanOrEqual(BuildColorScale::MIN_CONTRAST, "{$name} {$token} vs primary-700 {$palette}");
            }
        }
    }
});
