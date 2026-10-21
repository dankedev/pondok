<?php

use App\Actions\Konten\BuildColorScale;
use App\Actions\Konten\UpdatePortalTheme;
use App\Models\Setting;
use Illuminate\Validation\ValidationException;

/*
| F070 AC-2, AC-3, AC-4, AC-6. Kontrak: `UpdatePortalTheme::handle(array{primary?, accent?, font?, radius?} $data, User $actor): void`.
| Key `theme.primary|accent|font|radius`; nilai sama dengan bawaan (warna kosong, font `jakarta`, radius `medium`) disimpan null.
| Galat `pondok.konten.errors.theme_hex_invalid|theme_option_invalid|theme_primary_contrast|theme_accent_contrast` di field
| `primary|accent|font|radius`; log `konten` `portal_theme_updated` hanya bila berubah. `BuildColorScale` di-resolve dari container.
*/

const THEME_KEYS = ['theme.primary', 'theme.accent', 'theme.font', 'theme.radius'];

beforeEach(function () {
    $this->f = kontenBoot();
    $this->admin = kontenUser('admin-konten');
});

function themeSave(array $data): void
{
    app(UpdatePortalTheme::class)->handle($data, test()->admin);
}

function themeStoredCount(): int
{
    return Setting::query()->whereIn('key', THEME_KEYS)->whereNotNull('value')->count();
}

it('AC-2: saves normalised values and logs once; saving the same data again logs nothing', function () {
    themeSave(['primary' => '#1F2A5E', 'accent' => '#FFD452', 'font' => 'nunito', 'radius' => 'large']);

    expect(Setting::get('theme.primary'))->toBe('#1f2a5e')
        ->and(Setting::get('theme.accent'))->toBe('#ffd452')
        ->and(Setting::get('theme.font'))->toBe('nunito')
        ->and(Setting::get('theme.radius'))->toBe('large');

    $logs = kontenActivities()->where('description', 'portal_theme_updated');
    $changed = $logs->first()->properties['changed'];

    expect($logs)->toHaveCount(1)
        ->and($changed['theme.primary']['new'])->toBe('#1f2a5e')
        ->and($changed['theme.radius']['new'])->toBe('large');

    themeSave(['primary' => ' #1f2a5e ', 'accent' => '#ffd452', 'font' => 'nunito', 'radius' => 'large']);

    expect(kontenActivities()->where('description', 'portal_theme_updated'))->toHaveCount(1);
});

it('AC-3: stores the default values as null without logging', function () {
    themeSave(['primary' => '', 'accent' => null, 'font' => 'jakarta', 'radius' => 'medium']);

    expect(themeStoredCount())->toBe(0)
        ->and(kontenActivities())->toHaveCount(0);
});

it('AC-3: clears a stored colour back to null when the field is emptied', function () {
    themeSave(['primary' => '#1f2a5e', 'accent' => '#ffd452', 'font' => 'lora', 'radius' => 'small']);
    themeSave(['primary' => null, 'accent' => '', 'font' => 'jakarta', 'radius' => 'medium']);

    expect(themeStoredCount())->toBe(0)
        ->and(kontenActivities()->where('description', 'portal_theme_updated'))->toHaveCount(2);
});

it('AC-4: rejects a malformed colour on the right field and saves nothing', function (mixed $value) {
    foreach (['primary', 'accent'] as $field) {
        kontenAssertRejected(fn () => themeSave([$field => $value, 'font' => 'nunito']), 'theme_hex_invalid', $field);
    }

    expect(themeStoredCount())->toBe(0);
})->with([
    'colour name' => 'red',
    'short hex' => '#fff',
    'missing hash' => '1f2a5e',
    'non hex digit' => '#12345g',
    'css injection' => '#000000;}',
    'array' => [['#1f2a5e']],
]);

it('AC-4: rejects an unknown font or radius and saves nothing', function () {
    kontenAssertRejected(fn () => themeSave(['primary' => '#1f2a5e', 'font' => 'comic']), 'theme_option_invalid', 'font');
    kontenAssertRejected(fn () => themeSave(['primary' => '#1f2a5e', 'radius' => 'huge']), 'theme_option_invalid', 'radius');

    expect(themeStoredCount())->toBe(0)
        ->and(kontenActivities())->toHaveCount(0);
});

it('AC-6: rejects a primary whose scale fails the contrast rule and reports both ratios', function () {
    app()->bind(BuildColorScale::class, fn () => new class extends BuildColorScale
    {
        public function handle(string $hex, string $role): array
        {
            return [...THEME_DEFAULT_PRIMARY, 900 => '#777777'];
        }
    });

    try {
        themeSave(['primary' => '#1f2a5e']);
        $this->fail('ValidationException diharapkan.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('primary')
            ->and($e->errors()['primary'][0])->toContain('4,4:1')->toContain('4,5:1');
    }

    expect(themeStoredCount())->toBe(0);
});

it('AC-6: rejects an accent whose scale fails against the effective primary-950', function () {
    app()->bind(BuildColorScale::class, fn () => new class extends BuildColorScale
    {
        public function handle(string $hex, string $role): array
        {
            return [...THEME_DEFAULT_ACCENT, 500 => '#5a5a5a'];
        }
    });

    try {
        themeSave(['accent' => '#f8b800']);
        $this->fail('ValidationException diharapkan.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('accent')->and($e->errors())->not->toHaveKey('primary')
            ->and($e->errors()['accent'][0])->toContain('2,7:1');
    }

    expect(themeStoredCount())->toBe(0);
});

it('AC-6: accepts colours that looked risky before, because the rule judges the generated scale', function () {
    foreach ([['primary' => '#7a7a7a'], ['primary' => '#ffff00'], ['accent' => '#7a5700']] as $input) {
        themeSave($input);
    }

    expect(Setting::get('theme.primary'))->toBe('#ffff00')
        ->and(Setting::get('theme.accent'))->toBe('#7a5700');
});
