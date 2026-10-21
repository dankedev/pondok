<?php

use App\Actions\Konten\BuildColorScale;
use App\Actions\Konten\GetPortalTheme;
use App\Actions\Konten\UpdatePortalTheme;
use App\Filament\Pages\Site\Theme;
use App\Models\Setting;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| F070 VERIFY: celah injeksi, tipe nilai, konsistensi Action simpan vs baca, terminasi loop, kontras masukan ekstrem,
| akses Livewire, dan simpan parsial.
*/

const ADV_CSS_SHAPE = '/^:root\{(--[a-z0-9-]+: [^;<>{}"\\\\]+;)+\}$/';

beforeEach(function () {
    $this->f = kontenBoot();
    $this->admin = kontenUser('admin-konten');
    themeBuild();
});

/**
 * @return list<string>
 */
function advColors(): array
{
    $colors = ['#000000', '#ffffff', '#808080', '#fefefe', '#010101', '#ffff00', '#00ffff', '#ff00ff', '#ff0000', '#00ff00', '#0000ff', '#fff9e5', '#000a3c', '#ffd452'];

    foreach ([0, 64, 128, 192, 255] as $r) {
        foreach ([0, 96, 160, 255] as $g) {
            foreach ([0, 128, 255] as $b) {
                $colors[] = sprintf('#%02x%02x%02x', $r, $g, $b);
            }
        }
    }

    return array_values(array_unique($colors));
}

it('never prints anything but whitelisted tokens, whatever type or content the stored values have', function () {
    $garbage = [123, 1.5, true, false, [], ['#ffffff'], '', '   ', "#1f2a5e\n", ' #1f2a5e', '#1f2a5e ', '#1F2A5E;}', '#ｆｆｆｆｆｆ', '#1f2a5g', '#1f2a5', 'url(x)', '</style><script>1</script>', "#1f2a5e\0", 'jakarta'];

    foreach ($garbage as $i => $value) {
        themeSet(['primary' => $value, 'accent' => $value, 'font' => $value, 'radius' => $value]);

        $theme = app(GetPortalTheme::class)->handle();

        expect($theme['css'] === null || preg_match(ADV_CSS_SHAPE, $theme['css']) === 1)->toBeTrue("garbage #{$i}: ".var_export($theme['css'], true));
    }

    themeSet(['primary' => '#1F2A5E', 'accent' => '#FFD452', 'font' => 'x', 'radius' => 'large']);
    $css = app(GetPortalTheme::class)->handle()['css'];

    expect($css)->toMatch(ADV_CSS_SHAPE)->not->toMatch('/[A-F]/')->toContain('--radius-xl: 1.25rem;');
});

it('rejects non-string and markup input in every field without saving or logging', function () {
    $bad = [
        ['primary' => ['#1f2a5e']], ['primary' => 123456], ['primary' => true], ['primary' => '#ｆｆｆｆｆｆ'], ['primary' => '#1f2a5e;}</style>'],
        ['accent' => ['#ffd452']], ['accent' => "#ffd452\n#000000"], ['accent' => 'url(x)'],
        ['font' => ['lora']], ['font' => 'lora;}'], ['font' => true],
        ['radius' => ['large']], ['radius' => 'LARGE'], ['radius' => 5],
    ];

    foreach ($bad as $i => $data) {
        try {
            app(UpdatePortalTheme::class)->handle($data, $this->admin);
            $this->fail("input #{$i} diterima: ".json_encode($data));
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(array_keys($data));
        }
    }

    expect(Setting::query()->where('key', 'like', 'theme.%')->whereNotNull('value')->count())->toBe(0)
        ->and(kontenActivities())->toHaveCount(0);
});

it('keeps stored values for keys that are not sent and resets only the null ones', function () {
    app(UpdatePortalTheme::class)->handle(['primary' => '#1f2a5e', 'accent' => '#ffd452', 'font' => 'lora', 'radius' => 'small'], $this->admin);

    app(UpdatePortalTheme::class)->handle(['radius' => 'large'], $this->admin);

    expect(Setting::get('theme.primary'))->toBe('#1f2a5e')
        ->and(Setting::get('theme.accent'))->toBe('#ffd452')
        ->and(Setting::get('theme.font'))->toBe('lora')
        ->and(Setting::get('theme.radius'))->toBe('large');

    app(UpdatePortalTheme::class)->handle(['accent' => null, 'font' => 'jakarta'], $this->admin);

    expect(Setting::get('theme.primary'))->toBe('#1f2a5e')
        ->and(Setting::get('theme.accent'))->toBeNull()
        ->and(Setting::get('theme.font'))->toBeNull()
        ->and(Setting::get('theme.radius'))->toBe('large');

    $before = kontenActivities()->count();
    app(UpdatePortalTheme::class)->handle([], $this->admin);
    app(UpdatePortalTheme::class)->handle(['primary' => '#1F2A5E', 'radius' => 'large'], $this->admin);

    expect(kontenActivities())->toHaveCount($before);
});

it('terminates for every extreme colour pair and never prints a scale that fails contrast', function () {
    $colors = advColors();
    $started = microtime(true);
    $build = app(BuildColorScale::class);

    foreach ($colors as $hex) {
        foreach (['primary', 'accent'] as $role) {
            $scale = $build->handle($hex, $role);

            expect(array_keys($scale))->toBe(BuildColorScale::STEPS);

            foreach ($scale as $step => $value) {
                expect($value)->toMatch('/^#[0-9a-f]{6}$/', "{$role} {$hex} langkah {$step}");
            }
        }
    }

    foreach ($colors as $index => $primary) {
        // Pasangkan tiap warna dengan aksen yang berbeda agar kombinasi ekstrem ikut teruji.
        $accent = $colors[($index * 7 + 3) % count($colors)];
        themeSet(['primary' => $primary, 'accent' => $accent]);

        $css = app(GetPortalTheme::class)->handle()['css'];

        preg_match_all('/--color-(primary|accent)-(\d+): (#[0-9a-f]{6});/', (string) $css, $matches, PREG_SET_ORDER);
        $printed = ['primary' => [], 'accent' => []];

        foreach ($matches as [, $role, $step, $value]) {
            $printed[$role][(int) $step] = $value;
        }

        $effectivePrimary = $printed['primary'] ?: BuildColorScale::DEFAULT_PRIMARY;
        $effectiveAccent = $printed['accent'] ?: BuildColorScale::DEFAULT_ACCENT;

        expect(BuildColorScale::contrastFailure($effectivePrimary, $effectiveAccent))->toBeNull("{$primary} + {$accent}");
    }

    expect(microtime(true) - $started)->toBeLessThan(30.0);
});

it('agrees between the save action and the reader: a saved colour is printed, a refused one is not', function () {
    $colors = advColors();

    foreach ($colors as $hex) {
        foreach (['primary', 'accent'] as $field) {
            themeSet(['primary' => null, 'accent' => null]);

            try {
                app(UpdatePortalTheme::class)->handle([$field => $hex], $this->admin);
                $accepted = true;
            } catch (ValidationException) {
                $accepted = false;
            }

            $css = (string) app(GetPortalTheme::class)->handle()['css'];

            expect(str_contains($css, "--color-{$field}-"))->toBe($accepted, "{$field} {$hex}: simpan ".($accepted ? 'diterima' : 'ditolak').' tetapi baca '.($accepted ? 'membuangnya' : 'mencetaknya'));
        }
    }
});

it('forbids roles without view:school-profile from opening or saving the theme page through Livewire', function (string $role) {
    $this->actingAs(kontenUser($role));

    Livewire::test(Theme::class)->assertForbidden();

    expect(Setting::get('theme.primary'))->toBeNull();
})->with(['bendahara', 'admin-psb', 'wali', 'musyrif']);
