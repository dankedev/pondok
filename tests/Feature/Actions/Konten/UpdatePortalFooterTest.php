<?php

/*
| F046 AC-4 dan AC-5. Kontrak: `UpdatePortalFooter::handle(array $data, User $actor): void` dengan input
| `{footer_widgets: list<{type, visible, data}>}`; hasil di `portal.footer_widgets` (ternormalisasi), log
| `portal_footer_updated` hanya bila berubah. Maksimum 4 widget (termasuk yang tersembunyi), field galat
| `footer_widgets` (jumlah), `footer_widgets.{i}.type`, `footer_widgets.{i}.data.{heading|body|items}`,
| `footer_widgets.{i}.data.items.{j}.{type|url}`. Data: text {heading, body (HTML ≤6000 setelah sanitasi), show_logo},
| links {heading, items 1-12 tanpa grup, label ≤60}, social dan contacts {heading}. Heading ≤60.
*/

beforeEach(function () {
    $this->f = kontenBoot();
    $this->admin = kontenUser('admin-konten');
});

it('AC-4: saves every widget type in order, keeps visibility, and logs once', function () {
    $widgets = [
        ftr('text', ['heading' => 'Tentang', 'body' => '<p>Isi <strong>tebal</strong></p>', 'show_logo' => true]),
        ftr('contacts', ['heading' => 'Hubungi']),
        ftr('social', ['heading' => null], visible: false),
        ftr('links', ['heading' => 'Tautan', 'items' => [navItem('url', 'Berita', ['url' => '/berita']), navItem('psb.status')]]),
    ];

    ftrSave($widgets);

    $stored = ftrStored();

    expect(array_column($stored, 'type'))->toBe(['text', 'contacts', 'social', 'links'])
        ->and(array_column($stored, 'visible'))->toBe([true, true, false, true])
        ->and($stored[0]['data'])->toEqual(['heading' => 'Tentang', 'body' => '<p>Isi <strong>tebal</strong></p>', 'show_logo' => true])
        ->and($stored[1]['data'])->toEqual(['heading' => 'Hubungi'])
        ->and($stored[2]['data'])->toEqual(['heading' => null])
        ->and($stored[3]['data']['items'])->toEqual([navItem('url', 'Berita', ['url' => '/berita']), navItem('psb.status')])
        ->and(kontenActivities()->where('description', 'portal_footer_updated'))->toHaveCount(1);

    ftrSave($stored);

    expect(ftrStored())->toEqual($stored)
        ->and(kontenActivities()->where('description', 'portal_footer_updated'))->toHaveCount(1);
});

it('AC-4: sanitizes the text body on save, and an empty list is stored as empty', function () {
    ftrSave([ftr('text', ['body' => '<p>Aman</p><script>alert(1)</script><a href="javascript:alert(2)">klik</a>', 'show_logo' => false])]);

    expect(ftrStored()[0]['data']['body'])->toContain('Aman')->not->toContain('<script')->not->toContain('javascript:');

    ftrSave([]);

    expect(ftrStored())->toBe([]);
});

it('AC-5: rejects an invalid widget list with an error on the right field and changes nothing', function (array $widgets, string $field) {
    ftrSave([ftr('contacts', ['heading' => 'Lama'])]);

    ftrAssertRejected($widgets, $field, $field);
})->with(function (): array {
    $links = fn (array $items): array => [ftr('links', ['items' => $items])];
    $item = navItem('url', 'X', ['url' => '/berita']);

    return [
        'fifth widget, hidden ones count' => [[ftr('social'), ftr('social', visible: false), ftr('contacts'), ftr('contacts', visible: false), ftr('social')], 'footer_widgets'],
        'group item' => [$links([navItem('group', 'G', ['children' => [navItem('home')]])]), 'footer_widgets.0.data.items.0.type'],
        'unsafe url' => [$links([$item, navItem('url', 'Y', ['url' => 'javascript:alert(1)'])]), 'footer_widgets.0.data.items.1.url'],
        'protocol relative url' => [$links([navItem('url', 'Y', ['url' => '//evil.test'])]), 'footer_widgets.0.data.items.0.url'],
        'thirteen items' => [$links(array_fill(0, 13, $item)), 'footer_widgets.0.data.items'],
        'no items' => [$links([]), 'footer_widgets.0.data.items'],
        'label over 60' => [$links([navItem('url', str_repeat('a', 61), ['url' => '/berita'])]), 'footer_widgets.0.data.items.0.label'],
        'heading 61' => [[ftr('social', ['heading' => str_repeat('h', 61)])], 'footer_widgets.0.data.heading'],
        'body over 6000 after sanitizing' => [[ftr('text', ['body' => '<p>'.str_repeat('a', 6001).'</p>', 'show_logo' => true])], 'footer_widgets.0.data.body'],
    ];
});

it('AC-5: rejects the widget types that belong to the home page or do not exist', function () {
    foreach (['quote', 'cta', 'hero', 'feed', 'video', 'bukan-tipe'] as $type) {
        ftrAssertRejected([ftr('social'), ftr($type, ['heading' => 'X', 'text' => 'X'])], 'footer_widgets.1.type', $type);
    }
});

it('AC-5: accepts a body of exactly 6000 characters and twelve links', function () {
    $body = '<p>'.str_repeat('a', 5993).'</p>';

    expect(strlen($body))->toBe(6000);

    ftrSave([ftr('text', ['body' => $body, 'show_logo' => false]), ftr('links', ['items' => array_fill(0, 12, navItem('home'))])]);

    expect(ftrStored())->toHaveCount(2)->and(ftrStored()[1]['data']['items'])->toHaveCount(12);
});
