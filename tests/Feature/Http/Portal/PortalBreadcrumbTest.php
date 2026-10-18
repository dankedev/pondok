<?php

use App\Models\Post;
use App\Models\StaticPage;

beforeEach(function () {
    portalBoot();
    portalSeedContent();
});

/**
 * Isi elemen `<nav aria-label="…breadcrumb…">` pada HTML, atau string kosong bila tidak ada.
 */
function portalBreadcrumbHtml(string $html): string
{
    $label = preg_quote(__('pondok.portal.breadcrumb_label'), '#');
    preg_match('#<nav\b[^>]*aria-label="'.$label.'"[^>]*>(.*?)</nav>#s', $html, $matches);

    return $matches[1] ?? '';
}

it('F051: shows a breadcrumb above the heading on news and gallery lists', function (string $url, string $section) {
    $crumb = portalBreadcrumbHtml($this->get($url)->assertOk()->getContent());

    expect($crumb)->not->toBe('')
        ->toContain(__('pondok.portal.nav.home'))
        ->toContain($section);
})->with([
    'news' => ['/berita', 'Berita'],
    'gallery' => ['/galeri', 'Galeri'],
]);

it('F051: shows a breadcrumb on a static page', function () {
    $page = StaticPage::findBySlug('profil');
    $crumb = portalBreadcrumbHtml($this->get('/profil')->assertOk()->getContent());

    expect($crumb)->toContain(__('pondok.portal.nav.home'))->toContain($page->title);
});

it('F051: marks the current page as the last breadcrumb without a link', function () {
    $post = Post::query()->where('slug', 'wisuda-2026')->firstOrFail();
    $crumb = portalBreadcrumbHtml($this->get('/berita/wisuda-2026')->assertOk()->getContent());

    expect($crumb)->toContain(__('pondok.portal.nav.news'))
        ->toContain($post->title)
        ->toContain('aria-current="page"')
        ->not->toContain('href="'.$post->title);
});

it('F051: uses the title as alt on news and gallery covers', function () {
    portalPost(['title' => 'Berita Bersampul', 'cover_path' => 'posts/cover.jpg']);
    portalAlbumWithPhotos(1, ['title' => 'Album Bersampul']);

    expect($this->get('/berita')->assertOk()->getContent())->toContain('alt="Berita Bersampul"')
        ->and($this->get('/galeri')->assertOk()->getContent())->toContain('alt="Album Bersampul"');
});
