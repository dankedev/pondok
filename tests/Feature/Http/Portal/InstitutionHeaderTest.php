<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    portalBoot();
    Setting::set('school.name', "Ma'had Al-Khair");
});

/**
 * Taruh berkas logo palsu di disk `public` dan isi key settings-nya; kembalikan URL-nya.
 *
 * @param  array<string, string>  $files  key settings => path berkas
 * @return array<string, string>
 */
function profilLogos(array $files): array
{
    $urls = [];

    foreach ($files as $key => $path) {
        Storage::disk('public')->put($path, "isi-{$path}");
        Setting::set($key, $path);
        $urls[$key] = Storage::disk('public')->url($path);
    }

    return $urls;
}

it('AC-38: renders a picture for desktop and mobile logos', function () {
    $urls = profilLogos(['school.logo_desktop_path' => 'school/desktop.png', 'school.logo_mobile_path' => 'school/mobile.png']);

    $header = profilHeader($this->get('/')->assertOk()->getContent());

    expect($header)->toContain('<picture')
        ->and(profilTag($header, 'source', ['media="(min-width: 768px)"', 'srcset="'.$urls['school.logo_desktop_path'].'"']))->not->toBeNull()
        ->and(profilTag($header, 'img', ['src="'.$urls['school.logo_mobile_path'].'"', 'alt="'.e("Ma'had Al-Khair").'"']))->not->toBeNull()
        ->and(substr_count($header, '<img'))->toBe(1);
});

it('AC-39: renders only the desktop logo when there is no mobile logo', function () {
    $urls = profilLogos(['school.logo_desktop_path' => 'school/desktop.png']);

    $header = profilHeader($this->get('/')->assertOk()->getContent());

    expect(profilTag($header, 'img', ['src="'.$urls['school.logo_desktop_path'].'"', 'alt="'.e("Ma'had Al-Khair").'"']))->not->toBeNull()
        ->and($header)->not->toContain('<source')->not->toContain('<picture')
        ->and(substr_count($header, '<img'))->toBe(1)
        ->and($header)->toMatch('/<[a-z]+\b[^>]*'.profilClassPattern(['sr-only']).'[^>]*>\s*'.preg_quote(e("Ma'had Al-Khair"), '/').'\s*</');
});

it('AC-40: wraps a lone mobile logo in md:hidden and shows the name from md up', function () {
    $urls = profilLogos(['school.logo_mobile_path' => 'school/mobile.png']);

    $header = profilHeader($this->get('/')->assertOk()->getContent());

    expect($header)->toMatch('/<[a-z]+\b[^>]*'.profilClassPattern(['md:hidden']).'[^>]*>\s*<img\b[^>]*src="'.preg_quote($urls['school.logo_mobile_path'], '/').'"/')
        ->and($header)->toMatch('/<[a-z]+\b[^>]*'.profilClassPattern(['hidden', 'md:inline']).'[^>]*>\s*'.preg_quote(e("Ma'had Al-Khair"), '/').'/')
        ->and($header)->not->toContain('<picture')->not->toContain('<source');
});

it('AC-41: falls back to the name and never to the print logo in the header', function () {
    $urls = profilLogos(['school.logo_path' => 'school/cetak.png']);
    Setting::set('school.short_name', 'MAK');

    $header = profilHeader($this->get('/')->assertOk()->getContent());

    expect($header)->not->toContain('<img')->not->toContain($urls['school.logo_path'])->not->toContain('school/cetak.png')
        ->and(profilVisibleText($header))->toContain("Ma'had Al-Khair")->toContain('MAK');
});

it('AC-41: shows the full name when there is no short name and no logo at all', function () {
    $header = profilHeader($this->get('/')->assertOk()->getContent());

    expect($header)->not->toContain('<img')->and(profilVisibleText($header))->toContain("Ma'had Al-Khair");
});

it('AC-42: uses the favicon when it is set and favicon.ico otherwise', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect(profilTag($html, 'link', ['rel="icon"', asset('favicon.ico')]))->not->toBeNull()
        ->and(profilTag($html, 'link', ['rel="icon"', 'type="image/png"']))->toBeNull();

    $urls = profilLogos(['school.favicon_path' => 'school/favicon.png']);
    $html = $this->get('/')->assertOk()->getContent();

    expect(profilTag($html, 'link', ['rel="icon"', 'type="image/png"', 'href="'.$urls['school.favicon_path'].'"']))->not->toBeNull();
});

it('AC-42: uses the print logo for og:image and never the desktop logo', function () {
    $urls = profilLogos(['school.logo_path' => 'school/cetak.png', 'school.logo_desktop_path' => 'school/desktop.png']);

    $html = $this->get('/')->assertOk()->getContent();
    $og = profilTag($html, 'meta', ['property="og:image"']);

    expect($og)->not->toBeNull()->toContain($urls['school.logo_path'])->not->toContain('desktop.png');
});

it('AC-42: has no og:image when only the desktop logo is set', function () {
    profilLogos(['school.logo_desktop_path' => 'school/desktop.png']);

    expect(profilTag($this->get('/')->assertOk()->getContent(), 'meta', ['property="og:image"']))->toBeNull();
});

it('AC-43: keeps the header logo of a legacy install after the data migration', function () {
    Storage::disk('public')->put('school/cetak.png', 'logo-lama');
    profilRawSet('school.logo_path', 'school/cetak.png');

    profilMigration()->up();
    app()->forgetScopedInstances();

    $header = profilHeader($this->get('/')->assertOk()->getContent());
    $desktop = Setting::get('school.logo_desktop_path');

    expect($desktop)->toStartWith('school/')->not->toBe('school/cetak.png')
        ->and(profilTag($header, 'img', ['src="'.Storage::disk('public')->url($desktop).'"']))->not->toBeNull()
        ->and($header)->not->toContain('school/cetak.png');
});

it('AC-38: keeps the logo markup within the header height without horizontal scroll', function () {
    profilLogos(['school.logo_desktop_path' => 'school/desktop.png']);

    $img = profilTag(profilHeader($this->get('/')->assertOk()->getContent()), 'img', ['alt=']);

    expect($img)->toMatch('/'.profilClassPattern(['h-10', 'max-w-full', 'object-contain']).'/');
});
