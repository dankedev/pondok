<?php

use App\Actions\Konten\CreateStaticPage;
use App\Actions\Konten\DeleteStaticPage;
use App\Actions\Konten\UpdateStaticPage;
use App\Models\Setting;
use App\Models\StaticPage;

/*
| F037 VERIFY: celah pada Action (slug, hapus, menu, batas halaman).
*/

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
});

it('VERIFY: rejects explicit slugs that collide with routes, public files, reserved names and bad formats', function (string $slug, string $reason) {
    kontenAssertRejected(fn () => app(CreateStaticPage::class)->handle(['title' => 'X', 'slug' => $slug]), $reason, 'slug');
})->with([
    'route admin' => ['admin', 'slug_reserved'],
    'cadangan info-psb' => ['info-psb', 'slug_reserved'],
    'folder public css' => ['css', 'slug_reserved'],
    'folder public build' => ['build', 'slug_reserved'],
    'berkas public favicon.ico' => ['favicon.ico', 'slug_format'],
    'garis miring' => ['a/b', 'slug_format'],
    'titik' => ['a.b', 'slug_format'],
    'unicode' => ['halamán', 'slug_format'],
    'spasi dalam' => ['a b', 'slug_format'],
    'garis ganda' => ['a--b', 'slug_format'],
    'traversal' => ['../x', 'slug_format'],
]);

it('VERIFY: lowercases and trims an explicit slug with capitals', function () {
    $page = app(CreateStaticPage::class)->handle(['title' => 'X', 'slug' => '  Pengurus-Baru ']);

    expect($page->slug)->toBe('pengurus-baru');
});

it('VERIFY: a reserved slug cannot be reached after normalization by capitals', function () {
    kontenAssertRejected(fn () => app(CreateStaticPage::class)->handle(['title' => 'X', 'slug' => 'ADMIN']), 'slug_reserved', 'slug');
    kontenAssertRejected(fn () => app(CreateStaticPage::class)->handle(['title' => 'X', 'slug' => 'Info-PSB']), 'slug_reserved', 'slug');
});

it('VERIFY: generated slug from title avoids reserved names (info-psb, admin, css)', function (string $title, string $expected) {
    expect(app(CreateStaticPage::class)->handle(['title' => $title])->slug)->toBe($expected);
})->with([
    ['Info PSB', 'info-psb-2'],
    ['Admin', 'admin-2'],
    ['CSS', 'css-2'],
]);

it('VERIFY: a very long title generates a slug within 50 characters', function () {
    $page = app(CreateStaticPage::class)->handle(['title' => str_repeat('panjang ', 20)]);

    expect(strlen($page->slug))->toBeLessThanOrEqual(50)
        ->and($page->slug)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/');
});

it('VERIFY: a punctuation-only title does not produce an empty or invalid slug', function () {
    $slug = app(CreateStaticPage::class)->handle(['title' => '!!! ???'])->slug;

    expect($slug)->not->toBe('')->and($slug)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/');
});

it('VERIFY: a former system page keeps its slug when update omits slug or sends it with different case and spaces', function () {
    $page = StaticPage::query()->where('slug', 'kontak')->firstOrFail();

    app(UpdateStaticPage::class)->handle($page, ['title' => 'Hubungi Kami', 'slug' => ' Kontak ', 'status' => 'draft']);

    expect($page->fresh())->slug->toBe('kontak')->title->toBe('Hubungi Kami')->status->toBe('draft');
});

it('VERIFY: free page cannot take the slug of another free page nor of a system page', function () {
    staticPage(['slug' => 'satu']);
    $two = staticPage(['slug' => 'dua']);

    kontenAssertRejected(fn () => app(UpdateStaticPage::class)->handle($two, ['title' => 'Dua', 'slug' => 'satu']), 'slug_taken', 'slug');
    kontenAssertRejected(fn () => app(UpdateStaticPage::class)->handle($two, ['title' => 'Dua', 'slug' => 'kontak']), 'slug_taken', 'slug');
    kontenAssertRejected(fn () => app(UpdateStaticPage::class)->handle($two, ['title' => 'Dua', 'slug' => 'info-psb']), 'slug_reserved', 'slug');
});

it('VERIFY: page limit of 50 counts the initial pages and blocks the 51st', function () {
    $existing = StaticPage::query()->count();

    for ($i = 0; $i < StaticPage::MAX_PAGES - $existing; $i++) {
        app(CreateStaticPage::class)->handle(['title' => "Halaman uji {$i}"]);
    }

    expect(StaticPage::query()->count())->toBe(50);
    kontenAssertRejected(fn () => app(CreateStaticPage::class)->handle(['title' => 'Kelebihan']), 'page_limit_reached');
    expect(StaticPage::query()->count())->toBe(50);
});

it('VERIFY: rejects an invalid status on create and update', function () {
    kontenAssertRejected(fn () => app(CreateStaticPage::class)->handle(['title' => 'X', 'status' => 'published']), 'status_invalid', 'status');
    kontenAssertRejected(fn () => app(CreateStaticPage::class)->handle(['title' => 'X', 'status' => ['terbit']]), 'status_invalid', 'status');
    kontenAssertRejected(fn () => app(UpdateStaticPage::class)->handle(staticPage(), ['title' => 'X', 'status' => 'TERBIT']), 'status_invalid', 'status');
});

it('VERIFY: free page body is sanitized on create and update', function () {
    $page = app(CreateStaticPage::class)->handle(['title' => 'X', 'body' => '<p onclick="a()">Halo</p><script>alert(1)</script><a href="javascript:alert(1)">klik</a>']);

    expect($page->body)->toContain('Halo')->not->toContain('<script')->not->toContain('onclick')->not->toContain('javascript:');

    app(UpdateStaticPage::class)->handle($page, ['title' => 'X', 'body' => '<img src=x onerror=alert(1)><p>ok</p>']);

    expect($page->fresh()->body)->not->toContain('onerror')->toContain('ok');
});

it('VERIFY: delete is atomic, a failure while cleaning settings keeps the page', function () {
    $x = staticPage(['slug' => 'x-atomik']);
    navSet([navPage($x)], navPage($x));

    Setting::saving(fn () => throw new RuntimeException('boom'));

    try {
        app(DeleteStaticPage::class)->handle($x);
    } catch (RuntimeException) {
    }

    Setting::flushEventListeners();

    expect(StaticPage::query()->whereKey($x->id)->exists())->toBeTrue()
        ->and(Setting::get('portal.navigation_primary'))->toEqual([navPage($x)]);
});

it('VERIFY: delete ignores malformed settings values without crashing', function () {
    $x = staticPage(['slug' => 'x-rusak']);
    Setting::set('portal.navigation_primary', ['bukan-array', 5, null, navPage($x), navItem('home'), ['type' => 'group', 'label' => 'G', 'children' => 'rusak']]);
    Setting::set('portal.header_button', 'rusak');

    app(DeleteStaticPage::class)->handle($x);

    expect(StaticPage::query()->whereKey($x->id)->exists())->toBeFalse()
        ->and(collect(Setting::get('portal.navigation_primary'))->contains(fn (mixed $item): bool => is_array($item) && ($item['page_id'] ?? null) === $x->id))->toBeFalse();
});
