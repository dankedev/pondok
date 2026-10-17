<?php

use App\Actions\Konten\DeleteStaticPage;
use App\Actions\Konten\UpdateStaticPage;
use App\Filament\Resources\StaticPages\Pages\CreateStaticPage as CreateStaticPagePage;
use App\Filament\Resources\StaticPages\Pages\EditStaticPage;
use App\Models\StaticPage;
use Livewire\Livewire;

/*
| F048 VERIFY: celah setelah halaman sistem dihapus (menu bawaan, URL lama, form Filament).
*/

beforeEach(function () {
    portalBoot();
    portalContactData();
});

it('F048 gap: the portal still renders when all three former system pages are deleted and the menu is the default', function () {
    foreach (['profil', 'visi-misi', 'kontak'] as $slug) {
        app(DeleteStaticPage::class)->handle(StaticPage::findBySlug($slug));
    }

    foreach (['/', '/berita', '/profil', '/kontak', '/visi-misi'] as $url) {
        $this->get($url)->assertStatus(in_array($url, ['/profil', '/kontak', '/visi-misi'], true) ? 404 : 200);
    }

    expect(StaticPage::count())->toBe(0);
});

it('F048 gap: renaming kontak moves the contact card to the new url and the old url is 404', function () {
    $page = StaticPage::findBySlug('kontak');
    app(UpdateStaticPage::class)->handle($page, ['title' => $page->title, 'body' => '<p>Jam layanan</p>', 'slug' => 'hubungi-kami']);

    $this->get('/kontak')->assertNotFound();
    $this->get('/hubungi-kami')->assertOk()->assertSee('info@contoh.test');
    expect(StaticPage::findBySlug('hubungi-kami')->template)->toBe('contact');
});

it('F048 gap: a menu item pointing at a contact page by id follows the slug change', function () {
    $page = staticPage(['slug' => 'hubungi', 'title' => 'Hubungi', 'template' => 'contact']);
    app(UpdateStaticPage::class)->handle($page, ['title' => 'Hubungi', 'body' => '<p>x</p>', 'slug' => 'kontak-kami']);

    expect(portalDecoded($this->get('/')->assertOk()))->not->toContain('/hubungi"');
});

it('F048 gap: a tampered template from the edit form is rejected and nothing is saved', function () {
    $f = kontenBoot(withPages: true);
    $this->actingAs(kontenUser('admin-konten'));
    $page = StaticPage::findBySlug('profil');

    Livewire::test(EditStaticPage::class, ['record' => $page->getRouteKey()])
        ->set('data.template', 'wide')
        ->call('save')
        ->assertHasFormErrors(['template']);

    expect($page->fresh()->template)->toBe('default');
});

it('F048 gap: creating a page from the panel with the contact template stores it', function () {
    kontenBoot(withPages: true);
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(CreateStaticPagePage::class)
        ->fillForm(['title' => 'Hubungi Kami', 'template' => 'contact', 'status' => 'terbit'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(StaticPage::findBySlug('hubungi-kami'))->not->toBeNull()->template->toBe('contact');
});
