<?php

use App\Actions\Konten\UpdateStaticPage;
use App\Models\StaticPage;

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

it('AC-31: ignores a slug in the data', function () {
    kontenUpdatePage($this->page, ['slug' => 'beranda', 'title' => 'Profil Baru']);

    expect($this->page->fresh())->slug->toBe('profil')->title->toBe('Profil Baru')
        ->and(StaticPage::query()->pluck('slug')->sort()->values()->all())->toBe(['kontak', 'profil', 'visi-misi']);
});

it('AC-31: finds a page by slug', function () {
    expect(StaticPage::findBySlug('kontak')?->title)->toBe('Kontak')
        ->and(StaticPage::findBySlug('beranda'))->toBeNull()
        ->and(StaticPage::SLUGS)->toBe(['profil', 'visi-misi', 'kontak']);
});
