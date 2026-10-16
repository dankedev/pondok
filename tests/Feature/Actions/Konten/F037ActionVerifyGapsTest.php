<?php

use App\Actions\Konten\CreateStaticPage;
use App\Actions\Konten\DeleteStaticPage;
use App\Actions\Konten\UpdatePortalMenu;
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

it('VERIFY: system page slug cannot change, even for the same title or status change', function (string $slug) {
    $page = StaticPage::query()->where('slug', 'kontak')->firstOrFail();

    kontenAssertRejected(fn () => app(UpdateStaticPage::class)->handle($page, ['title' => 'Kontak', 'slug' => $slug]), 'slug_locked', 'slug');
    expect($page->fresh()->slug)->toBe('kontak');
})->with(['halaman-lain', 'KONTAK-2', 'profil']);

it('VERIFY: a system page keeps its slug when update omits slug and when slug differs only by case', function () {
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

it('VERIFY: page limit of 50 counts system pages and blocks the 51st', function () {
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

it('VERIFY: deleting a system page is refused for every path and leaves references untouched', function (string $slug) {
    $page = StaticPage::query()->where('slug', $slug)->firstOrFail();
    Setting::set('portal.menu_items', [menuPage($page)]);
    Setting::set('portal.footer_links', [['label' => null, 'page_id' => $page->id]]);

    kontenAssertRejected(fn () => app(DeleteStaticPage::class)->handle($page), 'page_system_locked');

    expect(StaticPage::query()->whereKey($page->id)->exists())->toBeTrue()
        ->and(Setting::get('portal.menu_items'))->toBe([menuPage($page)])
        ->and(Setting::get('portal.footer_links'))->toBe([['label' => null, 'page_id' => $page->id]]);
})->with(['profil', 'visi-misi', 'kontak']);

it('VERIFY: delete is atomic, a failure while cleaning settings keeps the page', function () {
    $x = staticPage(['slug' => 'x-atomik']);
    Setting::set('portal.menu_items', [menuPage($x)]);
    Setting::set('portal.footer_links', [['label' => null, 'page_id' => $x->id]]);

    Setting::saving(fn () => throw new RuntimeException('boom'));

    try {
        app(DeleteStaticPage::class)->handle($x);
    } catch (RuntimeException) {
    }

    Setting::flushEventListeners();

    expect(StaticPage::query()->whereKey($x->id)->exists())->toBeTrue();
});

it('VERIFY: delete ignores malformed settings values without crashing', function () {
    $x = staticPage(['slug' => 'x-rusak']);
    Setting::set('portal.menu_items', ['bukan-array', 5, null, menuPage($x), menuBuiltin('home')]);
    Setting::set('portal.footer_links', 'rusak');

    app(DeleteStaticPage::class)->handle($x);

    expect(StaticPage::query()->whereKey($x->id)->exists())->toBeFalse();
});

it('VERIFY: menu rejects more than 8 visible, unknown builtin, foreign page id, duplicates and bad types', function (array $items, string $reason) {
    kontenAssertRejected(fn () => app(UpdatePortalMenu::class)->handle(['items' => $items, 'show_register' => true], kontenUser('admin-konten')), $reason);
})->with(function () {
    $b = fn (string $k, bool $v = true) => ['type' => 'builtin', 'key' => $k, 'visible' => $v];
    $p = fn (int $id, bool $v = true) => ['type' => 'page', 'page_id' => $id, 'label' => null, 'visible' => $v];

    return [
        'builtin tak dikenal' => [[$b('admin')], 'menu_item_invalid'],
        'builtin key array' => [[['type' => 'builtin', 'key' => ['home'], 'visible' => true]], 'menu_item_invalid'],
        'page id asing' => [[$p(99999)], 'page_not_found'],
        'page id nol' => [[$p(0)], 'page_not_found'],
        'page id negatif' => [[$p(-1)], 'page_not_found'],
        'duplikat builtin' => [[$b('home'), $b('home', false)], 'menu_item_duplicate'],
        'duplikat page' => [[$p(1), $p(1, false)], 'menu_item_duplicate'],
        'tipe asing' => [[['type' => 'url', 'url' => 'https://evil.test', 'visible' => true]], 'menu_item_invalid'],
    ];
});

it('VERIFY: menu accepts exactly 8 visible and hidden items beyond that', function () {
    $pages = collect(range(1, 4))->map(fn ($i) => staticPage(['slug' => "m-{$i}"]));
    $items = [menuBuiltin('home'), menuBuiltin('news'), menuBuiltin('gallery'), menuBuiltin('videos'), menuBuiltin('psb_info', false)];
    foreach ($pages as $page) {
        $items[] = menuPage($page);
    }
    $items[] = menuPage(StaticPage::query()->where('slug', 'profil')->firstOrFail(), null, false);

    app(UpdatePortalMenu::class)->handle(['items' => $items, 'show_register' => true], kontenUser('admin-konten'));

    expect(count(Setting::get('portal.menu_items')))->toBe(10);
});

it('VERIFY: menu label over 30 characters is rejected or stored within 30', function () {
    $page = staticPage();
    $action = fn () => app(UpdatePortalMenu::class)->handle(['items' => [menuBuiltin('home'), menuPage($page, str_repeat('a', 31))], 'show_register' => true], kontenUser('admin-konten'));

    kontenAssertRejected($action, 'too_long');
});

it('VERIFY: menu label script tag is stored as plain text (no markup)', function () {
    $page = staticPage();
    app(UpdatePortalMenu::class)->handle(['items' => [menuPage($page, '<b>Tebal</b>')], 'show_register' => true], kontenUser('admin-konten'));

    $stored = Setting::get('portal.menu_items')[0]['label'] ?? '';
    expect($stored)->not->toContain('<b>');
});

it('VERIFY: menu rejects 9 visible items (draft pages still count)', function () {
    $items = [menuBuiltin('home'), menuBuiltin('news'), menuBuiltin('gallery'), menuBuiltin('videos'), menuBuiltin('psb_info')];
    foreach (range(1, 4) as $i) {
        $items[] = menuPage(staticPage(['slug' => "d-{$i}", 'status' => 'draft']));
    }

    kontenAssertRejected(fn () => app(UpdatePortalMenu::class)->handle(['items' => $items, 'show_register' => true], kontenUser('admin-konten')), 'menu_too_many', 'items');
});
