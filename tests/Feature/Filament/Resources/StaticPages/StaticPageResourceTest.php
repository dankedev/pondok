<?php

use App\Filament\Resources\StaticPages\Pages\EditStaticPage;
use App\Filament\Resources\StaticPages\Pages\ListStaticPages;
use App\Filament\Resources\StaticPages\StaticPageResource;
use App\Models\StaticPage;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
    $this->page = StaticPage::query()->where('slug', 'profil')->firstOrFail();
});

it('AC-32: cannot create or delete static pages', function (string $role) {
    $this->actingAs(kontenUser($role));

    $component = Livewire::test(ListStaticPages::class)
        ->assertCanSeeTableRecords(StaticPage::all())
        ->assertActionDoesNotExist('create')
        ->assertActionDoesNotExist(TestAction::make('delete')->table($this->page));

    expect(StaticPageResource::canCreate())->toBeFalse()
        ->and(StaticPageResource::canDelete($this->page))->toBeFalse()
        ->and($component->instance()->getTable()->getFlatBulkActions())->toBe([]);

    $this->get('/admin/static-pages/create')->assertNotFound();
})->with(['admin-konten', 'super-admin']);

it('AC-32: has no delete action on the edit page', function () {
    $this->actingAs(kontenUser('super-admin'));

    Livewire::test(EditStaticPage::class, ['record' => $this->page->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});

it('AC-32: saves the body sanitized from the edit page', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(EditStaticPage::class, ['record' => $this->page->getRouteKey()])
        ->fillForm(['title' => 'Profil Pondok', 'body' => '<p>Sejarah</p><script>alert(1)</script>'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->page->fresh())->title->toBe('Profil Pondok')->slug->toBe('profil')
        ->and($this->page->fresh()->body)->toContain('<p>Sejarah</p>')->not->toContain('<script');
});

it('AC-32: shows the slug but does not let it change', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(EditStaticPage::class, ['record' => $this->page->getRouteKey()])
        ->assertSchemaComponentExists('slug', checkComponentUsing: fn ($component): bool => $component->isDisabled())
        ->fillForm(['slug' => 'beranda', 'title' => 'Profil'])
        ->call('save');

    expect($this->page->fresh()->slug)->toBe('profil');
});

it('AC-32: shows a rejected data image as an error on the body field', function () {
    $this->actingAs(kontenUser('admin-konten'));

    $component = Livewire::test(EditStaticPage::class, ['record' => $this->page->getRouteKey()])
        ->fillForm(['body' => '<img src="data:image/png;base64,iVBORw0KGgo=">'])
        ->call('save')
        ->assertHasFormErrors(['body']);

    expect(kontenSeeMessage($component, 'media_source_invalid'))->toBeTrue()->and($this->page->fresh()->body)->toBe('');
});

it('AC-32: forbids static pages to other roles', function () {
    $this->actingAs(kontenUser('admin-sekolah'));

    $this->get('/admin/static-pages')->assertForbidden();
    $this->get("/admin/static-pages/{$this->page->id}/edit")->assertForbidden();
});
