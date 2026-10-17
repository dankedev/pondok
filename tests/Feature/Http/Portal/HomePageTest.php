<?php

use App\Models\Setting;
use App\Models\StaticPage;

/*
| Beranda berbasis blok (F045). Isi blok diuji lengkap di `HomeBlocksTest`; di sini hanya perilaku
| tombol pendaftaran PSB pada blok hero dan pemisahan dari halaman statis `profil`.
*/

beforeEach(function () {
    portalBoot();
    blkSet([blk('hero', ['title' => 'Judul hero uji', 'primary' => navItem('psb.register', 'Daftar sekarang uji')])]);
});

it('AC-8: shows the PSB call to action only when registration is open', function (string $state) {
    match ($state) {
        'open' => portalPsbOpen(),
        'off' => Setting::set('modules.psb.enabled', false),
        'closed' => portalPsbOpen(['is_open' => false]),
        'full' => (function () {
            portalPsbOpen(['quota' => 0]);
        })(),
    };

    $html = $this->get('/')->assertOk()->getContent();
    $cta = str_contains($html, __('pondok.portal.home.psb_open_badge'));

    if ($state === 'open') {
        expect($cta)->toBeTrue()
            ->and($html)->toContain(route('psb.register'))
            ->and($html)->toContain('Daftar sekarang uji');
    } else {
        expect($cta)->toBeFalse()->and($html)->not->toContain('Daftar sekarang uji');
    }

    if ($state === 'off') {
        expect($html)->not->toContain(route('psb.register'))->not->toContain(__('pondok.portal.nav.register'));
    } else {
        expect($html)->toContain(__('pondok.portal.nav.register'));
    }
})->with(['open', 'off', 'closed', 'full']);

it('AC-1: the home no longer follows the profile page; editing it changes nothing on the home', function () {
    StaticPage::findBySlug('profil')->update(['body' => '<p>Pondok kami berdiri sejak 1990.</p>']);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('Pondok kami berdiri sejak 1990.')->not->toContain('seksi-profil')
        ->not->toContain(route('portal.pages.show', 'profil').'" class="inline-flex');
});
