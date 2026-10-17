<?php

use App\Models\StaticPage;
use Illuminate\Support\Facades\Lang;

/**
 * Semua alasan penolakan Action konten (rencana F016, bagian teks).
 *
 * @var list<string>
 */
const KONTEN_ERROR_REASONS = [
    'invalid_type', 'title_required', 'too_long', 'slug_invalid', 'slug_taken', 'invalid_category', 'invalid_unit', 'body_required',
    'youtube_invalid', 'youtube_required', 'invalid_status', 'published_at_invalid', 'image_invalid', 'name_required',
    'category_name_taken', 'category_type_locked', 'category_in_use', 'photos_required', 'photos_too_many', 'sort_order_invalid',
    'media_source_invalid', 'phone_invalid', 'email_invalid', 'url_invalid', 'print_contact_multiple',
];

it('F016: translates every konten message', function (string $reason) {
    $key = "pondok.konten.errors.{$reason}";

    expect(__($key))->not->toBe($key)->not->toBe('')
        ->and(Lang::has($key, 'id'))->toBeTrue();
})->with(KONTEN_ERROR_REASONS);

it('F016: gives every konten message a way forward', function () {
    $messages = collect(KONTEN_ERROR_REASONS)->mapWithKeys(fn (string $reason): array => [$reason => __("pondok.konten.errors.{$reason}")]);

    expect($messages->unique()->count())->toBe($messages->count())
        ->and($messages['image_invalid'])->toContain('JPG')->toContain('PNG')->not->toContain('WEBP')
        ->and($messages['media_source_invalid'])->toContain('http')
        ->and($messages->every(fn (string $message): bool => str_ends_with($message, '.')))->toBeTrue();
});

it('F016: names the menu groups (F044: Situs replaces Konten, the cluster Profil lembaga is gone)', function () {
    expect(__('pondok.navigation.situs'))->toBe('Situs')
        ->and(__('pondok.navigation.pengaturan'))->toBe('Pengaturan');
});

it('F016: names the static page defaults', function () {
    expect(__('pondok.static_page.defaults.profil'))->toBe('Profil')
        ->and(__('pondok.static_page.defaults.visi-misi'))->toBe('Visi & Misi')
        ->and(__('pondok.static_page.defaults.kontak'))->toBe('Kontak');
});

it('AC-37 (F037): page and menu screens have no raw keys or content examples', function (string $url) {
    kontenBoot(withPages: true);
    $page = StaticPage::findBySlug('profil');
    $path = str_replace('{id}', (string) $page->id, $url);
    $html = $this->actingAs(kontenUser('super-admin'))->get($path)->assertOk()->getContent();

    preg_match_all('/placeholder="([^"]*)"/i', $html, $placeholders);
    $visible = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8').' '.implode(' ', $placeholders[1]);

    expect($html)->not->toMatch('/\bpondok\.[a-z_]+\.[a-z_]+/')
        ->and(implode(' ', $placeholders[1]))->not->toMatch('/\p{Arabic}|\bQS\b|bismillah|assalamu|ahlan/iu')
        ->and(__('pondok.static_page.slug_helper_create'))->not->toStartWith('pondok.')
        ->and($visible)->not->toBeEmpty();
})->with(['/admin/static-pages', '/admin/static-pages/create', '/admin/static-pages/{id}/edit', '/admin/site/navigation']);

it('AC-37 (F037): defines the new interface texts in lang/id', function () {
    foreach ([
        'pondok.portal.nav.psb_info', 'pondok.static_page.statuses.draft', 'pondok.static_page.statuses.terbit',
        'pondok.konten.errors.slug_format', 'pondok.konten.errors.slug_reserved', 'pondok.konten.errors.status_invalid',
        'pondok.konten.errors.page_not_found',
        'pondok.konten.errors.page_limit_reached', 'pondok.school_profile.pages.navigation',
    ] as $key) {
        expect(__($key))->not->toBe($key, "{$key} belum ada di lang/id");
    }
});

it('AC-12 (F048): defines the template texts in lang/id', function () {
    expectTranslatedKeys([
        'pondok.static_page.template', 'pondok.static_page.templates.default', 'pondok.static_page.templates.contact',
        'pondok.static_page.template_helper', 'pondok.konten.errors.template_invalid',
    ]);
});
