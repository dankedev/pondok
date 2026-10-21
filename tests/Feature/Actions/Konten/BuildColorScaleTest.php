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
