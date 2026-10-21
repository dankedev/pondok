<?php

use Filament\Facades\Filament;
use Filament\Support\Colors\Color;

/*
| F071 AC-8 sampai AC-11. Token merek `--color-brand`, `--color-brand-hover`, `--color-on-brand`, `--color-brand-text` ada di `@theme`
| `resources/css/app.css` (bawaan = primary-900/800, putih, primary-950), dipakai Blade portal/PSB lewat `bg-brand text-on-brand
| hover:bg-brand-hover`, `accent-brand`, dan `text-brand-text` (judul hero dan nama sekolah di header); panel admin tidak ikut.
*/

/**
 * Semua berkas Blade portal, komponen, layout publik, PSB, dan preset template (bukan panel Filament).
 *
 * @return list<string>
 */
function brandColorViews(): array
{
    $files = [];

    foreach (['portal', 'components', 'layouts', 'livewire/psb', 'themes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path("views/{$dir}"), FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('F071 AC-8: declares the brand tokens in @theme with defaults equal to the current look', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toMatch('/--color-brand:\s*var\(--color-primary-900\);/')
        ->toMatch('/--color-brand-hover:\s*var\(--color-primary-800\);/')
        ->toMatch('/--color-on-brand:\s*var\(--color-white\);/')
        ->toMatch('/--color-brand-text:\s*var\(--color-primary-950\);/');
});

it('F071 AC-9: no portal view keeps the fixed navy fill, hover or accent classes', function () {
    foreach (brandColorViews() as $file) {
        $source = file_get_contents($file);

        foreach (['bg-primary-900', 'hover:bg-primary-800', 'accent-primary-900'] as $class) {
            expect(preg_match('/(?<![\w:-])'.preg_quote($class, '/').'(?![\w-])/', $source))->toBe(0, basename($file)." masih memuat {$class}");
        }
    }
});

it('F071 AC-9: the default button and every hero title use the brand tokens', function () {
    expect(file_get_contents(resource_path('views/components/ui/button.blade.php')))->toContain('bg-brand text-on-brand')->toContain('hover:bg-brand-hover');

    foreach (['default', 'centered'] as $template) {
        $hero = file_get_contents(resource_path("views/themes/{$template}/blocks/hero.blade.php"));

        expect(preg_match('/<h1\b[^>]*text-brand-text/', $hero))->toBe(1, "hero {$template}");
    }
});

it('F071 AC-10: renders the chosen colour into the home page for both presets', function (string $template) {
    portalBoot();
    portalPsbOpen();
    themeSet(['primary' => '#15803d', 'template' => $template]);
    blkSet([blk('hero', ['title' => 'Judul hero uji', 'primary' => ['type' => 'url', 'label' => 'Tombol uji', 'url' => 'https://contoh.test/a']])]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('--color-brand: #15803d')
        ->and($html)->toMatch('/<h1\b[^>]*text-brand-text/')
        ->and($html)->toMatch('/<a\b[^>]*bg-brand[^>]*>\s*Tombol uji/')
        ->and($html)->toMatch('/<span\b[^>]*text-brand-text[^>]*>[^<]*Al-Khair/');
})->with(['default', 'centered']);

it('F071 AC-10: the registration form uses the brand accent for radios', function () {
    portalBoot();
    $wave = portalPsbOpen();
    psbWave($wave->academicYear, 'Gelombang 2', '2026-10-01', '2026-12-31');
    themeSet(['primary' => '#15803d']);

    expect($this->get('/psb')->assertOk()->getContent())->toContain('accent-brand')->not->toContain('accent-primary-900');
});

it('F071 AC-11: the admin panel does not get the brand tokens', function () {
    $this->f = kontenBoot();
    themeSet(['primary' => '#15803d']);

    $html = $this->actingAs(kontenUser('admin-konten'))->get('/admin/site/theme')->assertOk()->getContent();

    expect($html)->not->toContain('data-portal-theme')->not->toContain('--color-brand: ')
        ->and(Filament::getPanel('admin')->getColors()['primary'])->toEqual(Color::Emerald);
});
