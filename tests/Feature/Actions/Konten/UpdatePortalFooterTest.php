<?php

use App\Actions\Konten\UpdatePortalFooter;
use App\Models\Setting;

/*
| F043 AC-11 (sisi simpan). Kontrak: `UpdatePortalFooter::handle(array $data, User $actor): void` dengan
| `footer_description` (maks 500), `footer_text` ({title: ?string maks 60, body: ?string maks 300}),
| `footer_show_contacts` dan `footer_show_social` (bool, bawaan true bila tidak diberikan).
| Key: `portal.footer_description`, `portal.footer_text` ({title, body} atau null), `portal.footer_show_contacts`,
| `portal.footer_show_social`; log `portal_footer_updated`. Field kutipan dan tautan sudah tidak ada.
| Galat teks pendek: `footer_text.title` / `footer_text.body`.
*/

beforeEach(function () {
    $this->f = kontenBoot();
    $this->admin = kontenUser('admin-konten');
});

/**
 * @param  array<string, mixed>  $data
 */
function profilSaveFooter(array $data): void
{
    app(UpdatePortalFooter::class)->handle($data, test()->admin);
}

it('AC-11: saves the description, the short text and both switches, and logs once', function () {
    profilSaveFooter([
        'footer_description' => 'Deskripsi uji', 'footer_text' => ['title' => 'Jam Layanan', 'body' => "Senin-Jumat\n08.00-15.00"],
        'footer_show_contacts' => false, 'footer_show_social' => false,
    ]);

    expect(Setting::get('portal.footer_description'))->toBe('Deskripsi uji')
        ->and(Setting::get('portal.footer_text'))->toBe(['title' => 'Jam Layanan', 'body' => "Senin-Jumat\n08.00-15.00"])
        ->and(Setting::get('portal.footer_show_contacts'))->toBeFalse()
        ->and(Setting::get('portal.footer_show_social'))->toBeFalse()
        ->and(kontenActivities()->where('description', 'portal_footer_updated'))->toHaveCount(1);

    profilSaveFooter([
        'footer_description' => 'Deskripsi uji', 'footer_text' => ['title' => 'Jam Layanan', 'body' => "Senin-Jumat\n08.00-15.00"],
        'footer_show_contacts' => false, 'footer_show_social' => false,
    ]);

    expect(kontenActivities()->where('description', 'portal_footer_updated'))->toHaveCount(1);
});

it('AC-11: stores an empty body as null text, a title without a body is rejected, and a body without a title is fine', function () {
    profilSaveFooter(['footer_text' => ['title' => '', 'body' => '   ']]);

    expect(Setting::get('portal.footer_text'))->toBeNull();

    $before = profilSettingsSnapshot();

    profilAssertInvalid(fn () => profilSaveFooter(['footer_text' => ['title' => 'Judul saja', 'body' => '']]), 'footer_text.body');
    expect(profilSettingsSnapshot())->toBe($before);

    profilSaveFooter(['footer_text' => ['title' => '', 'body' => 'Isi saja']]);

    expect(Setting::get('portal.footer_text'))->toBe(['title' => null, 'body' => 'Isi saja']);
});

it('AC-11: switches default to true and an empty description is stored as null', function () {
    profilSaveFooter(['footer_description' => 'A']);
    profilSaveFooter(['footer_description' => '']);

    expect(Setting::get('portal.footer_description'))->toBeNull()
        ->and(Setting::get('portal.footer_show_contacts') ?? true)->toBeTrue()
        ->and(Setting::get('portal.footer_show_social') ?? true)->toBeTrue();
});

it('AC-11: enforces the length limits and changes nothing', function (array $input, string $field) {
    profilSaveFooter(['footer_description' => 'Lama']);
    $before = profilSettingsSnapshot();

    profilAssertInvalid(fn () => profilSaveFooter($input), $field);

    expect(profilSettingsSnapshot())->toBe($before);
})->with([
    'description 501' => [['footer_description' => str_repeat('a', 501)], 'footer_description'],
    'title 61' => [['footer_text' => ['title' => str_repeat('a', 61), 'body' => 'Isi']], 'footer_text.title'],
    'body 301' => [['footer_text' => ['title' => 'J', 'body' => str_repeat('a', 301)]], 'footer_text.body'],
]);

it('AC-11: accepts exactly the limits and keeps the text plain (no markup)', function () {
    profilSaveFooter(['footer_description' => str_repeat('a', 500), 'footer_text' => ['title' => str_repeat('b', 60), 'body' => str_repeat('c', 300)]]);

    expect(Setting::get('portal.footer_text')['title'])->toHaveLength(60)->and(Setting::get('portal.footer_text')['body'])->toHaveLength(300);

    profilSaveFooter(['footer_text' => ['title' => '<b>Tebal</b>', 'body' => '<script>alert(1)</script>']]);

    expect(json_encode(Setting::get('portal.footer_text')))->not->toContain('<b>')->not->toContain('<script');
});

it('AC-11: ignores the removed quote and link fields without writing the old keys', function () {
    profilSaveFooter([
        'footer_description' => 'A', 'footer_quote_arabic' => 'نص', 'footer_quote_translation' => 'T', 'footer_quote_source' => 'S',
        'footer_links' => [['label' => 'X', 'url' => '/berita']],
    ]);

    expect(Setting::query()->whereIn('key', ['portal.footer_quote_arabic', 'portal.footer_quote_translation', 'portal.footer_quote_source', 'portal.footer_links'])->count())->toBe(0);
});
