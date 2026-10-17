<?php

use App\Filament\Pages\Site\Footer;
use Filament\Forms\Components\Builder;
use Livewire\Livewire;

/*
| F046 AC-9 (halaman Footer). Kontrak halaman `App\Filament\Pages\Site\Footer` (slug `site/footer`, simpan lewat `save`).
| State form: `data.footer_widgets` = Builder (`maxItems(4)`), tiap item `{type, data: {visible, heading, ...}}` berkunci
| UUID; `text`: `body`, `show_logo`; `links`: `items` (Repeater Item tautan `{type, label, page_id, category_id, url}`);
| `social` dan `contacts` hanya `heading`. Saat simpan `visible` dipindah ke tingkat widget lalu `UpdatePortalFooter`
| dipanggil. Galat Action `footer_widgets.{i}.data.x` dipetakan ke `data.footer_widgets.{uuid}.data.x`.
*/

beforeEach(function () {
    $this->f = kontenBoot();
});

it('AC-9: lets the profile roles open the footer page and forbids the rest', function () {
    foreach (['super-admin' => 200, 'admin-sekolah' => 200, 'admin-konten' => 200, 'bendahara' => 403, 'admin-psb' => 403, 'kesantrian' => 403, 'musyrif' => 403, 'petugas-pos' => 403, 'wali' => 403] as $role => $status) {
        test()->actingAs(kontenUser($role))->get('/admin/site/footer')->assertStatus($status);
    }
});

it('AC-9: fills the builder from settings and drops link items whose target no longer exists', function () {
    $page = staticPage(['slug' => 'pengurus']);
    ftrSet([
        ftr('text', ['heading' => 'Tentang', 'body' => '<p>Isi uji</p>', 'show_logo' => true]),
        ftr('social', ['heading' => 'Ikuti'], visible: false),
        ftr('links', ['heading' => 'Tautan', 'items' => [navPage($page, 'Pengurus'), navPage(99999), navItem('url', 'Luar', ['url' => 'https://contoh.test'])]]),
    ]);
    $this->actingAs(kontenUser('admin-konten'));

    $items = array_values(Livewire::test(Footer::class)->get('data.footer_widgets') ?? []);
    $links = array_values($items[2]['data']['items'] ?? []);

    expect(array_column($items, 'type'))->toBe(['text', 'social', 'links'])
        ->and(array_column(array_column($items, 'data'), 'visible'))->toBe([true, false, true])
        ->and($items[0]['data'])->toMatchArray(['heading' => 'Tentang', 'show_logo' => true])
        ->and(json_encode($items[0]['data']['body'], JSON_UNESCAPED_UNICODE))->toContain('Isi uji')
        ->and(array_column($links, 'type'))->toBe(['page', 'url'])
        ->and($links[0]['page_id'])->toBe($page->id);
});

it('AC-9: limits the builder to four widgets', function () {
    $this->actingAs(kontenUser('admin-konten'));

    $component = Livewire::test(Footer::class);
    $builder = collect($component->instance()->form->getFlatComponents())->first(fn (mixed $field): bool => $field instanceof Builder);

    expect($builder)->not->toBeNull()->and($builder->getMaxItems())->toBe(4);
});

it('AC-9: saves added, reordered, hidden and removed widgets through the action', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $component = Livewire::test(Footer::class)
        ->set('data.footer_widgets', [
            'w-a' => ['type' => 'text', 'data' => ['visible' => true, 'heading' => 'Tentang', 'body' => '<p>Isi uji</p>', 'show_logo' => true]],
            'w-b' => ['type' => 'contacts', 'data' => ['visible' => false, 'heading' => 'Hubungi']],
            'w-c' => ['type' => 'links', 'data' => ['visible' => true, 'heading' => 'Tautan', 'items' => [['type' => 'url', 'label' => 'Berita', 'url' => '/berita']]]],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $stored = ftrStored();

    expect(array_column($stored, 'type'))->toBe(['text', 'contacts', 'links'])
        ->and(array_column($stored, 'visible'))->toBe([true, false, true])
        ->and($stored[0]['data'])->toMatchArray(['heading' => 'Tentang', 'show_logo' => true])
        ->and($stored[2]['data']['items'])->toEqual([navItem('url', 'Berita', ['url' => '/berita'])])
        ->and(kontenActivities()->where('description', 'portal_footer_updated'))->toHaveCount(1);

    $state = $component->get('data.footer_widgets');
    $keys = array_keys($state);

    $component->set('data.footer_widgets', [$keys[2] => $state[$keys[2]], $keys[0] => $state[$keys[0]]])->call('save')->assertHasNoFormErrors();

    expect(array_column(ftrStored(), 'type'))->toBe(['links', 'text']);
});

it('AC-9: shows a nested action error on the field of the right widget and saves nothing', function () {
    $this->actingAs(kontenUser('admin-konten'));
    ftrSave([ftr('contacts', ['heading' => 'Lama'])]);
    $before = profilSettingsSnapshot();

    $component = Livewire::test(Footer::class)
        ->set('data.footer_widgets', [
            'w-a' => ['type' => 'social', 'data' => ['visible' => true, 'heading' => 'Sah']],
            'w-b' => ['type' => 'links', 'data' => ['visible' => true, 'heading' => 'Bahaya', 'items' => [['type' => 'url', 'label' => 'Klik', 'url' => 'javascript:alert(1)']]]],
        ])
        ->call('save');

    expect(collect($component->errors()->keys())->contains(fn (string $key): bool => preg_match('/^data\.footer_widgets\.w-b\.data\.items\.[^.]+\.url$/', $key) === 1))->toBeTrue()
        ->and(profilSettingsSnapshot())->toBe($before);
});

it('AC-9: the footer page has no raw translation keys and no old fixed fields', function () {
    $html = $this->actingAs(kontenUser('super-admin'))->get('/admin/site/footer')->assertOk()->getContent();

    expect($html)->not->toMatch('/\bpondok\.[a-z_]+\.[a-z_]+/');

    Livewire::test(Footer::class)
        ->assertFormFieldExists('footer_widgets')
        ->assertFormFieldDoesNotExist('footer_description')
        ->assertFormFieldDoesNotExist('footer_show_contacts');
});
