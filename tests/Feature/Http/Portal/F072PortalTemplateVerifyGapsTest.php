<?php

use App\Filament\Pages\Site\Theme;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| F072 VERIFY: kasus tepi preset Terpusat dan ganti template lewat form Filament.
*/

beforeEach(function () {
    portalBoot();
});

it('renders the centered hero without media, buttons or album without errors', function () {
    Setting::set('theme.template', 'centered');
    blkSet([blk('hero', ['title' => 'Judul hero uji'])]);

    expect(blkHome())->toContain('Judul hero uji')->not->toContain('<img');
});

it('renders the centered hero album slider with the album photos', function () {
    Storage::fake('public');
    $album = portalAlbumWithPhotos(3, ['title' => 'Album hero uji']);
    Setting::set('theme.template', 'centered');
    blkSet([blk('hero', ['title' => 'Judul hero uji', 'album_id' => $album->id, 'image' => 'portal/blocks/hero.jpg'])]);

    $html = blkHome();

    expect($html)->toContain('role="region"')->toContain('aria-label="Album hero uji"')
        ->and(substr_count($html, 'snap-center'))->toBe(3)
        ->and($html)->not->toContain('portal/blocks/hero.jpg');
});

it('renders the centered quote as RTL Arabic and a single feature without a second column', function () {
    Setting::set('theme.template', 'centered');
    blkSet([
        blk('quote', ['text' => 'نص عربي للاختبار', 'rtl' => true]),
        blk('features', ['heading' => 'Keunggulan uji', 'items' => [['title' => 'Satu uji', 'text' => '']]]),
    ]);

    $html = blkHome();

    expect($html)->toContain('dir="rtl"')->toContain('lang="ar"')
        ->and((preg_match('#<section\b(?:(?!</section>).)*Keunggulan uji.*?</section>#is', $html, $m) ? $m[0] : ''))->toContain('Satu uji')->not->toContain('lg:grid-cols-2');
});

it('escapes user text in centered blocks', function () {
    Setting::set('theme.template', 'centered');
    blkSet([blk('hero', ['title' => '<script>alert(1)</script>']), blk('quote', ['text' => '<img src=x onerror=alert(2)>'])]);

    expect(blkHome())->not->toContain('<script>alert(1)')->not->toContain('<img src=x onerror');
});

it('switching the template in the Filament form keeps colours, font and radius', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(kontenUser('admin-konten'));
    Setting::set('theme.primary', '#1f2a5e');
    Setting::set('theme.font', 'lora');
    Setting::set('theme.radius', 'large');

    Livewire::test(Theme::class)->fillForm(['template' => 'centered'])->call('save')->assertHasNoErrors();

    expect(Setting::get('theme.primary'))->toBe('#1f2a5e')
        ->and(Setting::get('theme.font'))->toBe('lora')
        ->and(Setting::get('theme.radius'))->toBe('large')
        ->and(Setting::get('theme.template'))->toBe('centered');
});

it('portal uses the new preset right after saving through the form, without clearing the cache', function () {
    blkSet([blk('hero', ['title' => 'Judul hero uji', 'image' => 'portal/blocks/hero.jpg'])]);
    expect(blkHome())->toContain('lg:grid-cols-2');

    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(kontenUser('admin-konten'));
    Livewire::test(Theme::class)->fillForm(['template' => 'centered'])->call('save')->assertHasNoErrors();
    app()->forgetScopedInstances();

    expect(blkHome())->not->toContain('lg:grid-cols-2');
});

it('does not let a role without profile permission save the template', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(kontenUser('bendahara'));

    Livewire::test(Theme::class)->assertForbidden();

    expect(Setting::get('theme.template'))->toBeNull();
});
