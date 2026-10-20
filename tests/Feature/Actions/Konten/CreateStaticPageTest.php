<?php

use App\Actions\Konten\CreateStaticPage;
use App\Actions\Konten\SanitizeContentHtml;
use App\Models\StaticPage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
| F037 AC-4 sampai AC-9. Kontrak: `App\Actions\Konten\CreateStaticPage::handle(array $data): StaticPage`
| dengan key title, slug?, body, status?. Galat lewat ValidationException pondok.konten.errors.<reason>.
*/

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
});

/**
 * @param  array<string, mixed>  $data
 */
function kontenCreatePage(array $data = []): StaticPage
{
    return app(CreateStaticPage::class)->handle(['title' => 'Pengurus Yayasan', 'body' => '<p>Isi</p>', ...$data]);
}

it('AC-4: creates a draft page with sanitized body and a slug from the title', function () {
    $page = kontenCreatePage(['body' => '<p onclick="x()">Isi</p><script>alert(1)</script>']);

    expect($page)->toBeInstanceOf(StaticPage::class)
        ->and($page->slug)->toBe('pengurus-yayasan')->and($page->status)->toBe('draft')
        ->and($page->body)->toContain('<p>Isi</p>')->not->toContain('<script')->not->toContain('onclick')
        ->and(StaticPage::query()->count())->toBe(4);
});

it('AC-4: rejects media from a non-youtube host', function () {
    kontenAssertRejected(fn () => kontenCreatePage(['body' => '<iframe src="https://evil.test/x"></iframe>']), 'media_source_invalid', 'body');

    expect(StaticPage::query()->count())->toBe(3);
});

it('AC-5: normalizes an explicit slug and keeps the given status', function () {
    $page = kontenCreatePage(['slug' => '  Selayang-Pandang ', 'status' => 'terbit']);

    expect($page->slug)->toBe('selayang-pandang')->and($page->status)->toBe('terbit');
});

it('AC-6: rejects every reserved slug', function () {
    expect(StaticPage::RESERVED_SLUGS)->toContain('berita', 'galeri', 'video', 'psb', 'info-psb', 'sitemap', 'robots', 'verifikasi-surat', 'admin');

    foreach (StaticPage::RESERVED_SLUGS as $slug) {
        kontenAssertRejected(fn () => kontenCreatePage(['slug' => $slug]), 'slug_reserved', 'slug');
    }

    expect(StaticPage::query()->count())->toBe(3);
});

it('AC-6: rejects a slug equal to the first segment of a registered route', function () {
    Route::get('rute-uji-f037/{x}', fn () => 'ok');

    kontenAssertRejected(fn () => kontenCreatePage(['slug' => 'rute-uji-f037']), 'slug_reserved', 'slug');

    expect(StaticPage::query()->count())->toBe(3);
});

it('AC-6: rejects a slug equal to a name in the public path', function () {
    $folder = public_path('folder-uji-f037');
    File::ensureDirectoryExists($folder);

    try {
        kontenAssertRejected(fn () => kontenCreatePage(['slug' => 'folder-uji-f037']), 'slug_reserved', 'slug');
    } finally {
        File::deleteDirectory($folder);
    }

    expect(StaticPage::query()->count())->toBe(3);
});

it('AC-7: suffixes generated slugs that are reserved or taken', function () {
    expect(kontenCreatePage(['title' => 'Berita'])->slug)->toBe('berita-2')
        ->and(kontenCreatePage(['title' => 'Kontak'])->slug)->toBe('kontak-2');

    kontenAssertRejected(fn () => kontenCreatePage(['slug' => 'kontak']), 'slug_taken', 'slug');
});

it('AC-8: rejects malformed slugs', function (string $slug) {
    kontenAssertRejected(fn () => kontenCreatePage(['slug' => $slug]), 'slug_format', 'slug');

    expect(StaticPage::query()->count())->toBe(3);
})->with(['sitemap.xml', 'robots.txt', 'a/b', '-x', 'x-', 'a--b', 'ü-x']);

it('AC-8: rejects a slug of 51 characters', function () {
    kontenAssertRejected(fn () => kontenCreatePage(['slug' => str_repeat('a', 51)]), 'too_long', 'slug');

    expect(kontenCreatePage(['slug' => str_repeat('a', 50)])->slug)->toHaveLength(50);
});

it('AC-9: validates title, status and body size', function () {
    kontenAssertRejected(fn () => kontenCreatePage(['title' => '  ']), 'title_required', 'title');
    kontenAssertRejected(fn () => kontenCreatePage(['title' => str_repeat('a', 256)]), 'too_long', 'title');
    kontenAssertRejected(fn () => kontenCreatePage(['status' => 'published']), 'status_invalid', 'status');
    kontenAssertRejected(fn () => kontenCreatePage(['body' => str_repeat('a', SanitizeContentHtml::MAX_INPUT_BYTES + 1)]), 'too_long', 'body');

    expect(StaticPage::query()->count())->toBe(3);
});

it('AC-9: refuses the 51st page', function () {
    foreach (range(1, 47) as $i) {
        staticPage(['slug' => "uji-{$i}", 'title' => "Uji {$i}"]);
    }

    expect(StaticPage::query()->count())->toBe(StaticPage::MAX_PAGES);

    kontenAssertRejected(fn () => kontenCreatePage(['slug' => 'ke-51']), 'page_limit_reached');

    expect(StaticPage::query()->count())->toBe(50)->and(StaticPage::MAX_PAGES)->toBe(50);
});

/*
| F048 AC-3. `template` opsional (default `default`), nilai valid: default, contact.
*/

it('AC-3 (F048): saves the default template when none is given, and contact when asked, also for several pages', function () {
    expect(kontenCreatePage()->template)->toBe('default')
        ->and(kontenCreatePage(['title' => 'Hubungi Kami', 'template' => 'contact'])->template)->toBe('contact')
        ->and(kontenCreatePage(['title' => 'Kontak Cabang', 'template' => 'contact'])->template)->toBe('contact')
        ->and(StaticPage::query()->where('template', 'contact')->count())->toBe(3);
});

it('AC-3 (F048): rejects an unknown template and creates nothing', function (mixed $template) {
    kontenAssertRejected(fn () => kontenCreatePage(['template' => $template]), 'template_invalid', 'template');

    expect(StaticPage::query()->count())->toBe(3);
})->with(['wide' => ['wide'], 'empty string' => [''], 'array' => [['contact']], 'wrong case' => ['Contact']]);

/*
| F038 AC-1. Template `psb-info` selalu berslug `info-psb` (input slug diabaikan), satu halaman saja; template lain tetap
| tidak boleh memakai `info-psb`.
*/

it('AC-1 (F038): forces the info-psb slug for the psb-info template and allows only one such page', function () {
    $page = kontenCreatePage(['title' => 'Info Pendaftaran', 'slug' => 'apa-saja', 'template' => 'psb-info']);

    expect($page->slug)->toBe('info-psb')->and($page->template)->toBe('psb-info');

    kontenAssertRejected(fn () => kontenCreatePage(['title' => 'Info Kedua', 'template' => 'psb-info']), 'slug_taken', 'slug');
    kontenAssertRejected(fn () => kontenCreatePage(['title' => 'Info Ketiga', 'slug' => 'lain', 'template' => 'psb-info']), 'slug_taken', 'slug');
    kontenAssertRejected(fn () => kontenCreatePage(['title' => 'Biasa', 'slug' => 'info-psb', 'template' => 'default']), 'slug_reserved', 'slug');

    expect(StaticPage::query()->count())->toBe(4);
});
