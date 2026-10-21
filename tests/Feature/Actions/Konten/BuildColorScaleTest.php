<?php

use App\Actions\Konten\BuildColorScale;

/*
| F070 AC-5, AC-7, AC-8. Kontrak: `BuildColorScale::handle(string $hex, string $role): array<int,string>` (role `primary|accent`,
| kunci 50..950, nilai `#rrggbb` huruf kecil), `static contrast(string $a, string $b): float` (WCAG 2.x), dan
| `static contrastFailure(array $primaryScale, array $accentScale): ?array{field: 'primary'|'accent', ratio: float}`.
| Aturan: primary gagal bila putih vs 700/800/900/950 atau 100/200 vs 950 < 4,5; accent gagal bila 400/500 vs primary-950 < 4,5.
*/

it('computes the WCAG contrast ratio', function () {
    expect(BuildColorScale::contrast('#ffffff', '#000000'))->toEqualWithDelta(21.0, 0.01)
        ->and(BuildColorScale::contrast('#000000', '#ffffff'))->toEqualWithDelta(21.0, 0.01)
        ->and(BuildColorScale::contrast('#777777', '#ffffff'))->toEqualWithDelta(4.48, 0.02)
        ->and(BuildColorScale::contrast('#336699', '#336699'))->toEqualWithDelta(1.0, 0.001);
});

it('AC-5: accepts the default app.css scales', function () {
    expect(BuildColorScale::contrastFailure(THEME_DEFAULT_PRIMARY, THEME_DEFAULT_ACCENT))->toBeNull();
});

it('AC-5: flags a primary scale whose step 900 is too light for white text', function () {
    $failure = BuildColorScale::contrastFailure([...THEME_DEFAULT_PRIMARY, 900 => '#777777'], THEME_DEFAULT_ACCENT);

    expect($failure['field'])->toBe('primary')
        ->and($failure['ratio'])->toEqualWithDelta(4.48, 0.02);
});

it('AC-5: flags a primary scale whose footer text (100/200) is too dark against step 950', function () {
    $failure = BuildColorScale::contrastFailure([...THEME_DEFAULT_PRIMARY, 200 => '#2a2a6a'], THEME_DEFAULT_ACCENT);

    expect($failure['field'])->toBe('primary')->and($failure['ratio'])->toBeLessThan(4.5);
});

it('AC-5: flags an accent scale whose 500 is too dark for primary-950 text', function () {
    $failure = BuildColorScale::contrastFailure(THEME_DEFAULT_PRIMARY, [...THEME_DEFAULT_ACCENT, 500 => '#5a5a5a']);

    expect($failure['field'])->toBe('accent')
        ->and($failure['ratio'])->toEqualWithDelta(2.75, 0.02);
});

it('AC-5: judges the accent against the primary-950 of the given primary scale', function () {
    $accent = [...THEME_DEFAULT_ACCENT, 500 => '#777777'];

    expect(BuildColorScale::contrastFailure(THEME_DEFAULT_PRIMARY, $accent)['field'])->toBe('accent')
        ->and(BuildColorScale::contrastFailure([...THEME_DEFAULT_PRIMARY, 950 => '#000000'], $accent))->toBeNull();
});

it('AC-7: returns steps 50 to 950 as lowercase hex for every role', function (string $role, string $input) {
    $scale = app(BuildColorScale::class)->handle($input, $role);

    expect(array_keys($scale))->toBe(THEME_STEPS);

    foreach ($scale as $step => $hex) {
        expect($hex)->toMatch('/^#[0-9a-f]{6}$/', "{$role} langkah {$step}");
    }
})->with([
    'primary uppercase' => ['primary', '#1F2A5E'],
    'accent uppercase' => ['accent', '#F8B800'],
    'primary grey' => ['primary', '#808080'],
]);

it('AC-7: puts the input exactly on the anchor step', function (string $role, string $input, int $step) {
    expect(app(BuildColorScale::class)->handle($input, $role)[$step])->toBe(strtolower($input));
})->with([
    'primary 950' => ['primary', '#000a3c', 950],
    'primary 900' => ['primary', '#061247', 900],
    'primary 500' => ['primary', '#15803d', 500],
    'accent 500' => ['accent', '#f8b800', 500],
    'accent 500 uppercase' => ['accent', '#F8B800', 500],
]);

it('AC-7: rebuilds the default primary scale from its own anchor, getting lighter toward 50', function () {
    $scale = app(BuildColorScale::class)->handle('#1f2a5e', 'primary');

    expect($scale[700])->toBe('#1f2a5e')
        ->and(BuildColorScale::contrast($scale[50], '#000000'))->toBeGreaterThan(BuildColorScale::contrast($scale[500], '#000000'))
        ->and(BuildColorScale::contrast($scale[500], '#000000'))->toBeGreaterThan(BuildColorScale::contrast($scale[950], '#000000'));
});

it('AC-8: keeps every scale readable for the sampled primary and accent colours', function () {
    $primaries = ['#000a3c', '#1f2a5e', '#15803d', '#c00000', '#767676', '#000000', '#0000ff', '#ffff00'];
    $accents = ['#f8b800', '#ffff00', '#ffffff', '#22d3ee', '#7a5700'];
    $build = app(BuildColorScale::class);

    foreach ($primaries as $primary) {
        foreach ($accents as $accent) {
            $failure = BuildColorScale::contrastFailure($build->handle($primary, 'primary'), $build->handle($accent, 'accent'));

            expect($failure)->toBeNull("primary {$primary} + accent {$accent}: ".json_encode($failure));
        }
    }
});

/*
| F071 AC-1 sampai AC-5. Kontrak: `static BuildColorScale::brandColors(string $hex, array $scale): array{brand, brand_hover, on_brand,
| brand_text, fill_adjusted, text_adjusted}`; `$scale` = hasil `handle($hex, 'primary')`, teks gelap = `$scale[950]`, lolos = kontras >= 4,5.
*/

function brandOf(string $hex): array
{
    $scale = app(BuildColorScale::class)->handle($hex, 'primary');

    return [BuildColorScale::brandColors($hex, $scale), $scale];
}

it('F071 AC-1: keeps a mid colour as the fill with white text and a darker hover', function () {
    [$brand, $scale] = brandOf('#15803d');

    expect($brand)->toBe([
        'brand' => '#15803d',
        'brand_hover' => $scale[600],
        'on_brand' => '#ffffff',
        'brand_text' => '#15803d',
        'fill_adjusted' => false,
        'text_adjusted' => false,
    ]);
});

it('F071 AC-2: keeps a light colour as the fill with dark text and moves only the title colour darker', function () {
    [$brand, $scale] = brandOf('#facc15');
    $anchor = array_search('#facc15', $scale, true);
    $steps = BuildColorScale::STEPS;
    $textIndex = array_search($brand['brand_text'], array_map(fn (int $step): string => $scale[$step], $steps), true);
    $anchorIndex = array_search($anchor, $steps, true);

    expect($brand['brand'])->toBe('#facc15')
        ->and($brand['on_brand'])->toBe($scale[950])
        ->and(BuildColorScale::contrast('#facc15', $brand['on_brand']))->toBeGreaterThanOrEqual(4.5)
        ->and($brand['brand_hover'])->toBe($scale[$steps[$anchorIndex - 1]])
        ->and(BuildColorScale::contrast($brand['brand_hover'], $brand['on_brand']))->toBeGreaterThanOrEqual(4.5)
        ->and($brand['fill_adjusted'])->toBeFalse()
        ->and($brand['brand_text'])->not->toBe('#facc15')
        ->and($textIndex)->toBeGreaterThan($anchorIndex)
        ->and(BuildColorScale::contrast($brand['brand_text'], '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and($brand['text_adjusted'])->toBeTrue();

    if ($textIndex - 1 > $anchorIndex) {
        expect(BuildColorScale::contrast($scale[$steps[$textIndex - 1]], '#ffffff'))->toBeLessThan(4.5);
    }
});

it('F071 AC-3: keeps a dark colour with white text and a hover one step darker, or lighter at the end of the scale', function () {
    [$brand, $scale] = brandOf('#1a2c72');

    expect($brand)->toMatchArray(['brand' => '#1a2c72', 'brand_text' => '#1a2c72', 'on_brand' => '#ffffff', 'brand_hover' => $scale[800], 'fill_adjusted' => false, 'text_adjusted' => false]);

    foreach (['#000a3c', '#000000'] as $hex) {
        [$brand, $scale] = brandOf($hex);

        expect($brand['brand'])->toBe($hex, $hex)
            ->and($brand['on_brand'])->toBe('#ffffff', $hex)
            ->and($brand['brand_hover'])->toBe($scale[900], $hex);
    }
});

it('F071 AC-4: moves a fill that fits neither white nor dark text to the nearest passing step and derives the hover from it', function () {
    [$brand, $scale] = brandOf('#777777');

    expect(BuildColorScale::contrast('#777777', '#ffffff'))->toBeLessThan(4.5)
        ->and(BuildColorScale::contrast('#777777', $scale[950]))->toBeLessThan(4.5)
        ->and($brand)->toMatchArray(['brand' => $scale[500], 'on_brand' => '#ffffff', 'brand_hover' => $scale[600], 'fill_adjusted' => true]);

    [$brand, $scale] = brandOf('#ffffff');

    expect($brand)->toMatchArray(['brand' => '#ffffff', 'on_brand' => $scale[950], 'brand_hover' => $scale[100], 'text_adjusted' => true]);
});

it('F071 AC-5: every brand colour is readable and taken from the scale', function (string $hex) {
    [$brand, $scale] = brandOf($hex);

    expect($brand['brand'])->toBeIn($scale)
        ->and($brand['brand_hover'])->toBeIn($scale)
        ->and($brand['brand_text'])->toBeIn($scale)
        ->and($brand['on_brand'])->toBeIn(['#ffffff', $scale[950]])
        ->and(BuildColorScale::contrast($brand['brand'], $brand['on_brand']))->toBeGreaterThanOrEqual(4.5)
        ->and(BuildColorScale::contrast($brand['brand_hover'], $brand['on_brand']))->toBeGreaterThanOrEqual(4.5)
        ->and(BuildColorScale::contrast($brand['brand_text'], '#ffffff'))->toBeGreaterThanOrEqual(4.5);

    foreach (['brand', 'brand_hover', 'on_brand', 'brand_text'] as $key) {
        expect($brand[$key])->toMatch('/^#[0-9a-f]{6}$/', $key);
    }
})->with(['#15803d', '#facc15', '#1a2c72', '#000a3c', '#777777', '#808080', '#ffffff', '#000000', '#0000ff', '#ffff00', '#c00000', '#22d3ee']);
