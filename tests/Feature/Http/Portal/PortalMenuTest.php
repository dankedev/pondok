<?php

use App\Models\Setting;
use App\Models\StaticPage;
use Database\Seeders\StaticPageSeeder;
use Illuminate\Support\Facades\Route;

/*
| F037 AC-18 sampai AC-25 dan sisi portal AC-27a. Modul psb dimatikan di beforeEach (SettingSeeder menyalakannya)
| agar urutan nav deterministik; test yang butuh "Daftar" atau "Info PSB" menyalakannya sendiri.
*/

beforeEach(function () {
    portalBoot();
    Setting::set('modules.psb.enabled', false);
});

it('AC-18: the default menu matches the previous header', function () {
    portalPsbOpen();
    portalContactData();

    expect(portalNavTexts($this->get('/')->assertOk()->getContent()))
        ->toBe(['Beranda', 'Profil', 'Berita', 'Galeri', 'Video', 'Kontak', 'Masuk', 'Daftar']);
});

it('AC-19: a fresh install hides the draft profile and the contact page without contact data', function () {
    // Instalasi baru: seeder apa adanya; psb dimatikan (lihat beforeEach) agar tidak ada "Daftar".
    StaticPage::query()->delete();
    $this->seed(StaticPageSeeder::class);

    $texts = portalNavTexts($this->get('/')->assertOk()->getContent());

    expect($texts)->toBe(['Beranda', 'Berita', 'Galeri', 'Video', 'Masuk']);

    portalContactData();

    expect(portalNavTexts($this->get('/')->getContent()))->toBe(['Beranda', 'Berita', 'Galeri', 'Video', 'Kontak', 'Masuk']);
});

it('AC-20: the stored order and visibility drive both navs', function () {
    portalContactData();
    $pengurus = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus Al-Khair']);
    $selayang = staticPage(['slug' => 'selayang-pandang', 'title' => 'Selayang Pandang']);
    menuSet([
        menuPage($pengurus, 'Pengurus'), menuBuiltin('news', false), menuBuiltin('home'),
        menuPage($selayang), menuPage(StaticPage::findBySlug('kontak')),
    ]);

    expect(portalNavTexts($this->get('/')->assertOk()->getContent()))
        ->toBe(['Pengurus', 'Beranda', 'Selayang Pandang', 'Kontak', 'Galeri', 'Video', 'Masuk']);
});

it('AC-21: hides items whose page is a draft, deleted, or not visible', function () {
    $draft = staticPage(['slug' => 'draf', 'title' => 'Halaman Draf', 'status' => 'draft']);
    $gone = staticPage(['slug' => 'hilang', 'title' => 'Halaman Hilang']);
    $shown = staticPage(['slug' => 'tampil', 'title' => 'Halaman Tampil']);
    $hidden = staticPage(['slug' => 'sembunyi', 'title' => 'Halaman Sembunyi']);
    menuSet([menuBuiltin('home'), menuPage($draft), menuPage($gone), menuPage($shown), menuPage($hidden, null, false)]);
    StaticPage::query()->whereKey($gone->id)->delete(); // rujukan basi, tanpa Action

    $texts = portalNavTexts($this->get('/')->assertOk()->getContent());

    expect($texts)->toContain('Halaman Tampil')
        ->not->toContain('Halaman Draf')->not->toContain('Halaman Hilang')->not->toContain('Halaman Sembunyi');
});

it('AC-21: shows only Beranda and Masuk, with no content queries, when the content module is off', function () {
    staticPage(['slug' => 'pengurus', 'title' => 'Pengurus']);
    menuSet([menuBuiltin('home'), menuPage(StaticPage::findBySlug('pengurus')), menuBuiltin('news')]);
    Setting::set('modules.konten.enabled', false);
    $html = '';

    $queries = recordedQueries(function () use (&$html): void {
        $html = $this->get('/')->assertOk()->getContent();
    });

    expect(portalNavTexts($html))->toBe(['Beranda', 'Masuk'])
        ->and(queriesOnTable($queries, 'pages'))->toBe(0)
        ->and(queriesOnTable($queries, 'posts'))->toBe(0)
        ->and(queriesOnTable($queries, 'albums'))->toBe(0)
        ->and(queriesOnTable($queries, 'videos'))->toBe(0);
});

it('AC-21: shows the Info PSB item only when psb is on and the psb.info route exists', function () {
    Route::get('info-psb', fn () => 'ok')->name('psb.info');
    app('router')->getRoutes()->refreshNameLookups();

    expect(portalNavTexts($this->get('/')->getContent()))->not->toContain('Info PSB'); // modul psb mati

    Setting::set('modules.psb.enabled', true);
    expect(portalNavTexts($this->get('/')->getContent()))->toContain('Info PSB');
});

it('AC-21: never shows the Info PSB item while the route is missing', function () {
    Setting::set('modules.psb.enabled', true);

    expect(Route::has('psb.info'))->toBeFalse()
        ->and(portalNavTexts($this->get('/')->getContent()))->not->toContain('Info PSB');
});

it('AC-22: marks the active item in both navs', function () {
    $pengurus = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus']);
    menuSet([menuBuiltin('home'), menuPage($pengurus), menuBuiltin('news')]);
    portalPost(['title' => 'Wisuda 2026']);

    $links = portalNavLinks($this->get('/pengurus')->assertOk()->getContent());
    foreach (['desktop', 'mobile'] as $layout) {
        expect(collect($links[$layout])->where('current', true)->pluck('text')->all())->toBe(['Pengurus']);
    }

    $links = portalNavLinks($this->get('/berita/wisuda-2026')->assertOk()->getContent());
    foreach (['desktop', 'mobile'] as $layout) {
        expect(collect($links[$layout])->where('current', true)->pluck('text')->all())->toBe(['Berita']);
    }
});

it('AC-23: the register button can be hidden', function (?bool $show, bool $expected) {
    portalPsbOpen();
    menuSet(null, $show);

    expect(in_array('Daftar', portalNavTexts($this->get('/')->getContent()), true))->toBe($expected);
})->with([[false, false], [null, true], [true, true]]);

it('AC-24: resolves menu and footer pages with a single pages query and no repeated settings query', function () {
    $pages = collect(range(1, 4))->map(fn (int $i) => staticPage(['slug' => "menu-{$i}", 'title' => "Menu {$i}"]));
    $footer = collect(range(1, 2))->map(fn (int $i) => staticPage(['slug' => "foot-{$i}", 'title' => "Foot {$i}"]));
    menuSet([menuBuiltin('home'), ...$pages->map(fn ($p): array => menuPage($p))->all()]);
    Setting::set('portal.footer_links', $footer->map(fn ($p): array => ['label' => null, 'page_id' => $p->id])->all());

    $queries = recordedQueries(fn () => $this->get('/berita')->assertOk());

    $settings = array_map(fn (array $q): string => $q['sql'].json_encode($q['bindings']), array_filter($queries, fn (array $q): bool => str_contains($q['sql'], 'settings')));

    expect(queriesOnTable($queries, 'pages'))->toBe(1)
        ->and(count($settings))->toBe(count(array_unique($settings)));
});

it('AC-25: truncates long page titles in the menu but not in the heading', function () {
    $title = str_repeat('Panjang', 12).' selesai'; // 92 karakter
    staticPage(['slug' => 'panjang', 'title' => $title]);
    menuSet([menuBuiltin('home'), menuPage(StaticPage::findBySlug('panjang'))]);

    $html = $this->get('/panjang')->assertOk()->getContent();
    $label = portalNavTexts($html)[1];

    expect($label)->toEndWith('...')->and(mb_strlen($label))->toBeLessThan(mb_strlen($title))
        ->and(mb_strlen($label))->toBeLessThanOrEqual(33)
        ->and(str_starts_with($label, mb_substr($title, 0, 30)))->toBeTrue()
        ->and($html)->toMatch('/<h1[^>]*>\s*'.preg_quote($title, '/').'\s*<\/h1>/');
});

it('AC-27a: the portal shows only the first eight visible items of a hand-edited menu', function () {
    $pages = collect(range(1, 6))->map(fn (int $i) => staticPage(['slug' => "p-{$i}", 'title' => "Halaman {$i}"]));
    menuSet([menuBuiltin('home'), menuBuiltin('news'), menuBuiltin('gallery'), menuBuiltin('videos'), ...$pages->map(fn ($p): array => menuPage($p))->all()]);

    expect(portalNavTexts($this->get('/')->assertOk()->getContent()))
        ->toBe(['Beranda', 'Berita', 'Galeri', 'Video', 'Halaman 1', 'Halaman 2', 'Halaman 3', 'Halaman 4', 'Masuk']);
});
