<?php

use App\Models\Setting;
use App\Models\StaticPage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    portalBoot();
});

it('AC-14: shows the PSB call to action only when registration is open', function (string $state) {
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
            ->and($html)->toContain(__('pondok.portal.home.psb_cta'));
    } else {
        expect($cta)->toBeFalse()->and($html)->not->toContain(__('pondok.portal.home.psb_cta'));
    }

    if ($state === 'off') {
        expect($html)->not->toContain(route('psb.register'))->not->toContain(__('pondok.portal.nav.register'));
    } else {
        expect($html)->toContain(__('pondok.portal.nav.register'));
    }
})->with(['open', 'off', 'closed', 'full']);

it('AC-15: shows latest news and achievements', function () {
    foreach (range(1, 7) as $i) {
        portalPost([
            'title' => sprintf('Kabar harian %d', $i), 'type' => $i % 2 ? 'berita' : 'kegiatan',
            'published_at' => CarbonImmutable::parse('2026-10-14 08:00', 'Asia/Jakarta')->subDays($i - 1),
        ]);
    }
    portalPost(['title' => 'Juara Olimpiade', 'type' => 'prestasi']);
    kontenPost(['title' => 'Draf Rahasia', 'type' => 'prestasi']);
    portalPost(['title' => 'Jadwal Nanti', 'published_at' => CarbonImmutable::parse('2026-10-20 08:00', 'Asia/Jakarta')]);

    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'Kabar harian'))->toBe(6)
        ->and($html)->not->toContain('Kabar harian 7')
        ->toContain('Juara Olimpiade')
        ->not->toContain('Draf Rahasia')->not->toContain('Jadwal Nanti')
        ->toContain(__('pondok.portal.home.latest_news'))
        ->toContain(__('pondok.portal.home.latest_achievements'));
});

it('AC-16: shows featured videos and albums with photos', function () {
    kontenVideo(['title' => 'Video Satu', 'youtube_id' => 'AAAAAAAAAAA', 'is_featured' => true]);
    kontenVideo(['title' => 'Video Dua', 'youtube_id' => 'BBBBBBBBBBB', 'is_featured' => true]);
    kontenVideo(['title' => 'Video Tiga', 'youtube_id' => 'CCCCCCCCCCC', 'is_featured' => false]);
    portalAlbumWithPhotos(2, ['title' => 'Album Berfoto']);
    kontenAlbum(['title' => 'Album Kosong']);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('embed/AAAAAAAAAAA')->toContain('embed/BBBBBBBBBBB')
        ->not->toContain('CCCCCCCCCCC')
        ->toContain('Album Berfoto')->not->toContain('Album Kosong');
});

it('AC-17: shows profile summary and hides empty sections', function () {
    StaticPage::findBySlug('profil')->update(['body' => '<p>Pondok kami berdiri sejak 1990.</p>']);

    $this->get('/')->assertOk()
        ->assertSee('Pondok kami berdiri sejak 1990.')
        ->assertSee(route('portal.pages.show', 'profil'), false);

    StaticPage::findBySlug('profil')->update(['body' => '']);
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('seksi-profil')
        ->not->toContain(__('pondok.portal.home.latest_news'))
        ->not->toContain(__('pondok.portal.home.featured_videos'))
        ->not->toContain(__('pondok.portal.home.latest_albums'));
});

it('AC-18: skips content queries when konten is disabled', function () {
    portalSeedContent();
    Setting::set('modules.konten.enabled', false);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->get('/')->assertOk();

    $content = array_filter($queries, fn (string $sql): bool => (bool) preg_match('/\b(?:from|join)\s+["`\[]?(?:posts|albums|videos|pages)\b/i', $sql));
    expect($content)->toBeEmpty();
});
