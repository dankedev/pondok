<?php

use App\Models\Setting;
use Database\Seeders\SettingSeeder;

// Beranda adalah portal publik F017 (`portal.home`, stateless). Detail portal ada di tests/Feature/Http/Portal.

beforeEach(function () {
    $this->withoutVite();
    $this->seed(SettingSeeder::class);
    Setting::set('school.name', "Ma'had Al-Khair");
});

it('shows the school identity and a login link to a guest', function () {
    Setting::set('school.tagline', "Mencetak generasi Qur'ani");

    $this->get('/')
        ->assertOk()
        ->assertSee("Ma'had Al-Khair")
        ->assertSee("Mencetak generasi Qur'ani")
        ->assertSee(route('filament.admin.auth.login'), escape: false)
        ->assertSeeText(__('pondok.portal.nav.login'));
});

it('shows the PSB register link and the status check link only while the PSB module is enabled', function () {
    Setting::set('modules.psb.enabled', true);
    ftrSet([ftr('links', ['items' => [navItem('psb.status')]])]);

    $this->get('/')
        ->assertSee(route('psb.register'), escape: false)
        ->assertSee(route('psb.status'), escape: false)
        ->assertSeeText(__('pondok.portal.nav.psb_status'));

    Setting::set('modules.psb.enabled', false);

    $this->get('/')
        ->assertOk()
        ->assertDontSee(route('psb.register'), escape: false)
        ->assertDontSee(route('psb.status'), escape: false);
});

it('shows the letter verification link in the footer only while the dokumen module is enabled', function () {
    Setting::set('modules.dokumen.enabled', true);
    ftrSet([ftr('links', ['items' => [navItem('letters.verify')]])]);
    $html = $this->get('/')->assertOk()->assertSee(route('dokumen.letters.verify-form'), escape: false)
        ->assertSeeText(__('pondok.portal.nav.letters_verify'))->getContent();
    expect(preg_match('/<footer\b.*href="'.preg_quote(route('dokumen.letters.verify-form'), '/').'".*<\/footer>/s', $html))->toBe(1);

    Setting::set('modules.dokumen.enabled', false);
    $this->get('/')->assertOk()->assertDontSee(route('dokumen.letters.verify-form'), escape: false);
});

it('escapes the school name from settings', function () {
    Setting::set('school.name', '<script>x</script>');

    $this->get('/')
        ->assertOk()
        ->assertDontSee('<script>x</script>', escape: false)
        ->assertSee('&lt;script&gt;x&lt;/script&gt;', escape: false);
});
