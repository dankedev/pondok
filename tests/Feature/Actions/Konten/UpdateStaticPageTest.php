<?php

use App\Actions\Konten\UpdateStaticPage;
use App\Models\StaticPage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
    $this->page = StaticPage::query()->where('slug', 'profil')->firstOrFail();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function kontenUpdatePage(StaticPage $page, array $overrides = []): StaticPage
{
    return app(UpdateStaticPage::class)->handle($page, ['title' => 'Profil', 'body' => '<p>Isi</p>', ...$overrides]);
}

it('AC-31: sanitizes the body when saving', function () {
    kontenUpdatePage($this->page, ['body' => '<p>Isi</p><script>alert(1)</script>']);

    expect($this->page->fresh()->body)->toContain('<p>Isi</p>')->not->toContain('<script');
});

it('AC-31: rejects data media sources', function () {
    kontenAssertRejected(fn () => kontenUpdatePage($this->page, ['body' => '<img src="data:image/png;base64,iVBORw0KGgo=">']), 'media_source_invalid', 'body');

    expect($this->page->fresh()->body)->toBe('');
});

it('AC-31: counts body bytes, not characters', function () {
    kontenAssertRejected(fn () => kontenUpdatePage($this->page, ['body' => kontenArabicBody(250000)]), 'too_long', 'body');

    kontenUpdatePage($this->page, ['body' => kontenArabicBody(200000)]);

    expect(strip_tags($this->page->fresh()->body))->toBe(str_repeat('ب', 200000));
});

it('AC-31: allows an empty body', function () {
    kontenUpdatePage($this->page, ['body' => '<p>Isi</p>']);
    kontenUpdatePage($this->page, ['body' => '']);

    expect($this->page->fresh()->body)->toBe('');
});

it('AC-31: requires a title of at most 255 characters', function () {
    kontenAssertRejected(fn () => kontenUpdatePage($this->page, ['title' => '  ']), 'title_required', 'title');
    kontenAssertRejected(fn () => kontenUpdatePage($this->page, ['title' => str_repeat('a', 256)]), 'too_long', 'title');

    expect($this->page->fresh()->title)->toBe('Profil');
});

it('AC-31: keeps the slug when the same slug is sent', function () {
    kontenUpdatePage($this->page, ['slug' => 'profil', 'title' => 'Profil Baru']);

    expect($this->page->fresh())->slug->toBe('profil')->title->toBe('Profil Baru')
        ->and(StaticPage::query()->pluck('slug')->sort()->values()->all())->toBe(['kontak', 'profil', 'visi-misi']);
});

it('AC-31: finds a page by slug', function () {
    expect(StaticPage::findBySlug('kontak')?->title)->toBe('Kontak')
        ->and(StaticPage::findBySlug('beranda'))->toBeNull();
});

/*
| F037 AC-10 dan AC-11. Test lama di atas tetap berlaku (slug/status opsional).
*/

it('AC-10 (F037): updates slug and status of a free page', function () {
    $page = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus', 'status' => 'draft']);

    kontenUpdatePage($page, ['title' => 'Pengurus Yayasan', 'slug' => 'pengurus-yayasan', 'status' => 'terbit']);

    expect($page->fresh())->slug->toBe('pengurus-yayasan')->title->toBe('Pengurus Yayasan')->status->toBe('terbit');
});

it('AC-10 (F037): rejects reserved and taken slugs and leaves the row unchanged', function () {
    $page = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus']);
    staticPage(['slug' => 'lain', 'title' => 'Lain']);

    kontenAssertRejected(fn () => kontenUpdatePage($page, ['slug' => 'berita']), 'slug_reserved', 'slug');
    kontenAssertRejected(fn () => kontenUpdatePage($page, ['slug' => 'lain']), 'slug_taken', 'slug');
    kontenAssertRejected(fn () => kontenUpdatePage($page, ['slug' => 'Sitemap.xml']), 'slug_format', 'slug');

    expect($page->fresh())->slug->toBe('pengurus')->title->toBe('Pengurus');
});

it('AC-10 (F037): keeps slug and status when only title and body are sent', function () {
    $page = staticPage(['slug' => 'pengurus', 'status' => 'draft']);

    app(UpdateStaticPage::class)->handle($page, ['title' => 'Baru', 'body' => '<p>Isi baru</p>']);

    expect($page->fresh())->slug->toBe('pengurus')->status->toBe('draft')->title->toBe('Baru');
});

it('AC-10 (F037): keeps an unchanged slug that now collides with a route or a public folder', function () {
    $page = staticPage(['slug' => 'rute-uji-f037', 'title' => 'Lama']);
    $other = staticPage(['slug' => 'halaman-lain', 'title' => 'Lain']);
    Route::get('rute-uji-f037/{x}', fn () => 'ok');
    $folder = public_path('rute-uji-f037');
    File::ensureDirectoryExists($folder);

    try {
        kontenUpdatePage($page, ['title' => 'Baru', 'slug' => 'rute-uji-f037']);
        app(UpdateStaticPage::class)->handle($page->fresh(), ['title' => 'Baru Lagi', 'body' => '<p>x</p>']);
        kontenAssertRejected(fn () => kontenUpdatePage($other, ['slug' => 'rute-uji-f037']), 'slug_reserved', 'slug');
    } finally {
        File::deleteDirectory($folder);
    }

    expect($page->fresh())->title->toBe('Baru Lagi')->slug->toBe('rute-uji-f037')
        ->and($other->fresh()->slug)->toBe('halaman-lain');
});

/*
| F048 AC-4 dan AC-5: template dapat diubah, slug halaman awal tidak lagi terkunci.
*/

it('AC-4 (F048): changes the template, and keeps it when the template is not sent', function () {
    kontenUpdatePage($this->page, ['template' => 'contact']);
    expect($this->page->fresh()->template)->toBe('contact');

    kontenUpdatePage($this->page->fresh(), ['title' => 'Profil Lagi']);
    expect($this->page->fresh())->template->toBe('contact')->title->toBe('Profil Lagi');

    kontenUpdatePage($this->page->fresh(), ['template' => 'default']);
    expect($this->page->fresh()->template)->toBe('default');
});

it('AC-4 (F048): rejects an unknown template and leaves the page unchanged', function (mixed $template) {
    kontenAssertRejected(fn () => kontenUpdatePage($this->page, ['title' => 'Berubah', 'template' => $template]), 'template_invalid', 'template');

    expect($this->page->fresh())->template->toBe('default')->title->toBe('Profil');
})->with(['wide' => ['wide'], 'empty string' => [''], 'array' => [['contact']]]);

it('AC-5 (F048): the former system pages can change slug, with the same validation as free pages', function (string $from, string $to) {
    $page = StaticPage::query()->where('slug', $from)->firstOrFail();

    kontenUpdatePage($page, ['title' => $page->title, 'slug' => $to]);

    expect($page->fresh()->slug)->toBe($to);
})->with([['profil', 'tentang-kami'], ['visi-misi', 'visi'], ['kontak', 'hubungi-kami']]);

it('AC-5 (F048): rejects a bad, reserved or taken slug on a former system page', function () {
    kontenAssertRejected(fn () => kontenUpdatePage($this->page, ['slug' => 'Tentang Kami']), 'slug_format', 'slug');
    kontenAssertRejected(fn () => kontenUpdatePage($this->page, ['slug' => 'berita']), 'slug_reserved', 'slug');
    kontenAssertRejected(fn () => kontenUpdatePage($this->page, ['slug' => 'kontak']), 'slug_taken', 'slug');

    expect($this->page->fresh()->slug)->toBe('profil');
});
