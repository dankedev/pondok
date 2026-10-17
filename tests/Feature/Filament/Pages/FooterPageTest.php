<?php

use App\Filament\Pages\Site\Footer;
use App\Models\Setting;
use Livewire\Livewire;

/*
| F043 AC-13 (halaman Footer). Kontrak state form `Footer`: `data.footer_description`, `data.footer_text.title`,
| `data.footer_text.body`, `data.footer_show_contacts`, `data.footer_show_social`. Field kutipan dan tautan
| (`footer_quote_*`, `footer_links`) tidak ada lagi; simpan memanggil `UpdatePortalFooter` lewat `save`.
*/

beforeEach(function () {
    $this->f = kontenBoot();
});

it('AC-13: fills the footer form from settings and defaults the switches to on', function () {
    $this->actingAs(kontenUser('admin-konten'));

    $component = Livewire::test(Footer::class);

    expect($component->get('data.footer_show_contacts'))->toBeTrue()->and($component->get('data.footer_show_social'))->toBeTrue();

    profilSet(['portal.footer_description' => 'Deskripsi', 'portal.footer_text' => ['title' => 'Jam', 'body' => 'Isi'], 'portal.footer_show_contacts' => false]);
    app()->forgetScopedInstances();

    Livewire::test(Footer::class)
        ->assertSchemaStateSet(['footer_description' => 'Deskripsi', 'footer_text' => ['title' => 'Jam', 'body' => 'Isi'], 'footer_show_contacts' => false, 'footer_show_social' => true]);
});

it('AC-13: saves the description, short text and switches and shows no quote or link fields', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Footer::class)
        ->assertFormFieldDoesNotExist('footer_quote_arabic')
        ->assertFormFieldDoesNotExist('footer_quote_translation')
        ->assertFormFieldDoesNotExist('footer_links')
        ->fillForm(['footer_description' => 'Deskripsi uji', 'footer_text' => ['title' => 'Jam Layanan', 'body' => "Senin\nSelasa"], 'footer_show_contacts' => false, 'footer_show_social' => true])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Setting::get('portal.footer_description'))->toBe('Deskripsi uji')
        ->and(Setting::get('portal.footer_text'))->toBe(['title' => 'Jam Layanan', 'body' => "Senin\nSelasa"])
        ->and(Setting::get('portal.footer_show_contacts'))->toBeFalse()
        ->and(Setting::get('portal.footer_show_social'))->toBeTrue();
});

it('AC-13: shows the short text error on the right field and saves nothing', function () {
    $this->actingAs(kontenUser('admin-konten'));

    $component = Livewire::test(Footer::class)
        ->fillForm(['footer_description' => 'Baru', 'footer_text' => ['title' => 'Judul tanpa isi', 'body' => '']])
        ->call('save');

    expect($component->errors()->keys())->toContain('data.footer_text.body')
        ->and(Setting::get('portal.footer_description'))->toBeNull();
});

it('AC-13: the footer page has no raw translation keys and no Arabic quote example', function () {
    $html = $this->actingAs(kontenUser('super-admin'))->get('/admin/site/footer')->assertOk()->getContent();

    expect($html)->not->toMatch('/\bpondok\.[a-z_]+\.[a-z_]+/')
        ->and(profilTag($html, 'textarea', ['dir="rtl"', 'lang="ar"']))->toBeNull();
});
