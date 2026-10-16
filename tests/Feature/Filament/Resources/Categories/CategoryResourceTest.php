<?php

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Models\Category;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->actingAs(kontenUser('admin-konten'));
});

it('AC-23: creates a category from the modal', function () {
    Livewire::test(ManageCategories::class)
        ->assertActionVisible('create')
        ->callAction('create', ['type' => 'album', 'name' => 'Fasilitas'])
        ->assertHasNoFormErrors();

    expect(Category::query()->sole())->type->toBe('album')->name->toBe('Fasilitas')->slug->toBe('fasilitas');
});

it('AC-23: shows a taken name as an error on the name field', function () {
    kontenCategory('album', 'Fasilitas');

    $component = Livewire::test(ManageCategories::class)
        ->callAction('create', ['type' => 'album', 'name' => 'Fasilitas'])
        ->assertHasFormErrors(['name']);

    expect(kontenSeeMessage($component, 'category_name_taken'))->toBeTrue()
        ->and(Category::query()->count())->toBe(1);
});

it('AC-23: blocks deleting categories in use and deletes the others', function () {
    $byAlbum = kontenCategory('album', 'Dipakai album');
    kontenAlbum(['category_id' => $byAlbum->id]);
    $byTrashedPost = kontenCategory('post', 'Dipakai post');
    kontenPost(['category_id' => $byTrashedPost->id])->delete();
    $free = kontenCategory('album', 'Bebas');

    Livewire::test(ManageCategories::class)
        ->assertActionHidden(TestAction::make('delete')->table($byAlbum))
        ->assertActionHidden(TestAction::make('delete')->table($byTrashedPost))
        ->assertActionVisible(TestAction::make('delete')->table($free))
        ->callAction(TestAction::make('delete')->table($free));

    expect(Category::query()->find($free->id))->toBeNull()
        ->and(Category::query()->whereKey([$byAlbum->id, $byTrashedPost->id])->count())->toBe(2);
});

it('AC-23: locks the type of a category in use on edit', function () {
    $used = kontenCategory('album', 'Dipakai');
    kontenAlbum(['category_id' => $used->id]);
    $free = kontenCategory('album', 'Bebas');

    Livewire::test(ManageCategories::class)
        ->mountAction(TestAction::make('edit')->table($used))
        ->assertSchemaComponentExists('type', checkComponentUsing: fn ($component): bool => $component->isDisabled());

    Livewire::test(ManageCategories::class)
        ->mountAction(TestAction::make('edit')->table($free))
        ->assertSchemaComponentExists('type', checkComponentUsing: fn ($component): bool => ! $component->isDisabled());
});

it('AC-23: filters the table by type', function () {
    $album = kontenCategory('album', 'Galeri');
    $post = kontenCategory('post', 'Berita');

    Livewire::test(ManageCategories::class)
        ->filterTable('type', 'post')
        ->assertCanSeeTableRecords([$post])
        ->assertCanNotSeeTableRecords([$album]);
});

it('AC-23: forbids the category page to other roles', function () {
    $this->actingAs(kontenUser('admin-sekolah'));

    $this->get('/admin/categories')->assertForbidden();
});
