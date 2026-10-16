<?php

use App\Actions\Konten\UpdatePortalFooter;
use App\Models\Setting;

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

it('AC-30: saves the footer quote, description and links', function () {
    profilSaveFooter([
        'footer_quote_arabic' => 'نص عربي للاختبار', 'footer_quote_translation' => 'Terjemah uji', 'footer_quote_source' => 'Sumber uji',
        'footer_description' => 'Deskripsi uji',
        'footer_links' => [['label' => 'Berita', 'url' => '/berita'], ['label' => 'PSB', 'url' => 'https://contoh.test/psb']],
    ]);

    expect(Setting::get('portal.footer_quote_arabic'))->toBe('نص عربي للاختبار')
        ->and(Setting::get('portal.footer_quote_translation'))->toBe('Terjemah uji')
        ->and(Setting::get('portal.footer_quote_source'))->toBe('Sumber uji')
        ->and(Setting::get('portal.footer_description'))->toBe('Deskripsi uji')
        ->and(array_column(Setting::get('portal.footer_links'), 'url'))->toBe(['/berita', 'https://contoh.test/psb'])
        ->and(array_column(Setting::get('portal.footer_links'), 'label'))->toBe(['Berita', 'PSB'])
        ->and(kontenActivities()->where('description', 'portal_footer_updated'))->toHaveCount(1);
});

it('AC-30: stores empty values as null and logs nothing when unchanged', function () {
    profilSaveFooter(['footer_description' => 'A']);
    profilSaveFooter(['footer_description' => 'A']);

    expect(kontenActivities()->where('description', 'portal_footer_updated'))->toHaveCount(1);

    profilSaveFooter(['footer_description' => '', 'footer_links' => []]);

    expect(Setting::get('portal.footer_description'))->toBeNull()->and(Setting::get('portal.footer_links'))->toBeNull();
});

it('AC-30: enforces the length limits', function (array $input, string $field) {
    $before = profilSettingsSnapshot();

    kontenAssertRejected(fn () => profilSaveFooter($input), 'too_long', $field);

    expect(profilSettingsSnapshot())->toBe($before);
})->with([
    'arabic 1001' => [['footer_quote_arabic' => str_repeat('ب', 1001)], 'footer_quote_arabic'],
    'translation 1001' => [['footer_quote_translation' => str_repeat('a', 1001)], 'footer_quote_translation'],
    'source 101' => [['footer_quote_source' => str_repeat('a', 101)], 'footer_quote_source'],
    'description 501' => [['footer_description' => str_repeat('a', 501)], 'footer_description'],
    'link label 61' => [['footer_links' => [['label' => str_repeat('a', 61), 'url' => '/berita']]], 'footer_links.0.label'],
]);

it('AC-30: rejects nine links and a link without a label', function (array $links) {
    profilAssertInvalid(fn () => profilSaveFooter(['footer_links' => $links]), 'footer_links');
})->with([
    'nine' => [array_map(fn (int $i): array => ['label' => "L{$i}", 'url' => '/berita'], range(1, 9))],
    'no label' => [[['label' => '', 'url' => '/berita']]],
]);

it('AC-31: rejects unsafe footer links and changes nothing', function (string $url) {
    profilSaveFooter(['footer_description' => 'Lama']);
    $before = profilSettingsSnapshot();

    kontenAssertRejected(fn () => profilSaveFooter(['footer_description' => 'Baru', 'footer_links' => [['label' => 'X', 'url' => $url]]]), 'link_invalid', 'footer_links.0.url');

    expect(profilSettingsSnapshot())->toBe($before);
})->with([
    'protocol relative' => ['//evil.test'],
    'backslash' => ['/\\evil.test'],
    'tab' => ["/\t/evil.test"],
    'newline' => ["/\n/evil.test"],
    'javascript' => ['javascript:x'],
    'data' => ['data:text/html,x'],
    'relative without slash' => ['berita'],
    'space' => ['/a b'],
    'ftp' => ['ftp://x.test'],
]);
