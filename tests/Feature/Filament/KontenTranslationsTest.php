<?php

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
    'media_source_invalid', 'phone_invalid', 'email_invalid', 'url_invalid',
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

it('F016: names the menu groups and the school profile', function () {
    expect(__('pondok.navigation.konten'))->toBe('Konten')
        ->and(__('pondok.navigation.pengaturan'))->toBe('Pengaturan')
        ->and(__('pondok.school_profile.title'))->toBe('Profil sekolah');
});

it('F016: names the static page defaults', function () {
    expect(__('pondok.static_page.defaults.profil'))->toBe('Profil')
        ->and(__('pondok.static_page.defaults.visi-misi'))->toBe('Visi & Misi')
        ->and(__('pondok.static_page.defaults.kontak'))->toBe('Kontak');
});
