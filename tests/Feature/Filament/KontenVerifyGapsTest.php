<?php

use App\Filament\Pages\SchoolProfile;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\StaticPages\Pages\EditStaticPage;
use App\Filament\Resources\Videos\Pages\ManageVideos;
use App\Models\Photo;
use App\Models\Post;
use App\Models\Setting;
use App\Models\StaticPage;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
    $this->category = kontenCategory('album', 'Fasilitas');
    $this->album = kontenAlbum(['category_id' => $this->category->id]);
    $this->photo = kontenPhoto($this->album, ['caption' => 'Asli']);
    $this->post = kontenPost();
    $this->page = StaticPage::query()->where('slug', 'profil')->firstOrFail();
});

function kontenRelationManager(object $album): Testable
{
    return Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class]);
}

it('VERIFY: returns 403 for every content edit page when the module is off', function (string $role) {
    Setting::set('modules.konten.enabled', false);
    $this->actingAs(kontenUser($role));

    $this->get("/admin/albums/{$this->album->id}/edit")->assertForbidden();
    $this->get("/admin/posts/{$this->post->id}/edit")->assertForbidden();
    $this->get("/admin/static-pages/{$this->page->id}/edit")->assertForbidden();
    $this->get('/admin/albums/create')->assertForbidden();
    $this->get('/admin/posts/create')->assertForbidden();
})->with(['super-admin', 'admin-konten']);

it('VERIFY: refuses the content Livewire pages when the module is off', function () {
    Setting::set('modules.konten.enabled', false);
    $this->actingAs(kontenUser('super-admin'));

    Livewire::test(EditPost::class, ['record' => $this->post->getRouteKey()])->assertForbidden();
    Livewire::test(CreatePost::class)->assertForbidden();
    Livewire::test(ListPosts::class)->assertForbidden();
    Livewire::test(EditAlbum::class, ['record' => $this->album->getRouteKey()])->assertForbidden();
    Livewire::test(CreateAlbum::class)->assertForbidden();
    Livewire::test(ListAlbums::class)->assertForbidden();
    Livewire::test(EditStaticPage::class, ['record' => $this->page->getRouteKey()])->assertForbidden();
    Livewire::test(ManageVideos::class)->assertForbidden();
    Livewire::test(ManageCategories::class)->assertForbidden();
});

it('VERIFY: does not let the photos relation manager upload photos when the module is off', function () {
    Setting::set('modules.konten.enabled', false);
    $this->actingAs(kontenUser('admin-konten'));

    kontenRelationManager($this->album)
        ->assertActionHidden(TestAction::make('upload')->table())
        ->mountAction(TestAction::make('upload')->table())
        ->assertActionNotMounted(TestAction::make('upload')->table());

    expect(Photo::query()->count())->toBe(1)->and(kontenFiles())->toBe([$this->photo->path]);
});

it('VERIFY: does not let the photos relation manager delete photos when the module is off', function () {
    Setting::set('modules.konten.enabled', false);
    $this->actingAs(kontenUser('admin-konten'));

    kontenRelationManager($this->album)
        ->assertActionHidden(TestAction::make('delete')->table($this->photo))
        ->mountAction(TestAction::make('delete')->table($this->photo))
        ->assertActionNotMounted(TestAction::make('delete')->table($this->photo));

    expect(Photo::query()->count())->toBe(1)->and(Storage::disk('public')->exists($this->photo->path))->toBeTrue();
});

it('VERIFY: does not let the photos relation manager edit photos when the module is off', function () {
    Setting::set('modules.konten.enabled', false);
    $this->actingAs(kontenUser('admin-konten'));

    kontenRelationManager($this->album)
        ->assertActionHidden(TestAction::make('edit')->table($this->photo))
        ->mountAction(TestAction::make('edit')->table($this->photo))
        ->assertActionNotMounted(TestAction::make('edit')->table($this->photo));

    expect($this->photo->fresh()->caption)->toBe('Asli');
});

it('VERIFY: gives a view-only user no way to upload, edit or delete photos', function () {
    $this->actingAs(dashboardUserWith(['view-any:album', 'view:album']));

    kontenRelationManager($this->album)
        ->assertActionHidden(TestAction::make('upload')->table())
        ->assertActionHidden(TestAction::make('edit')->table($this->photo))
        ->assertActionHidden(TestAction::make('delete')->table($this->photo));

    expect(Photo::query()->count())->toBe(1)->and(kontenFiles())->toBe([$this->photo->path]);
});

it('VERIFY: lets a user without update or delete permission neither save nor delete from the pages', function () {
    $this->actingAs(dashboardUserWith(['view-any:post', 'view:post', 'view-any:album', 'view:album']));

    $this->get("/admin/posts/{$this->post->id}/edit")->assertForbidden();
    $this->get("/admin/albums/{$this->album->id}/edit")->assertForbidden();
    Livewire::test(ListAlbums::class)->assertActionHidden(TestAction::make('delete')->table($this->album));
    Livewire::test(ListPosts::class)->assertActionHidden(TestAction::make('delete')->table($this->post));
});

it('VERIFY: keeps the school profile page closed to a user holding only content permissions', function () {
    $this->actingAs(dashboardUserWith(['view-any:post', 'update:static-page', 'view-dashboard']));

    $this->get('/admin/school-profile')->assertForbidden();
    Livewire::test(SchoolProfile::class)->assertForbidden();
});

it('VERIFY: does not let a form request overwrite the author or the slug of a post', function () {
    $this->actingAs($editor = kontenUser('admin-konten'));
    $other = kontenUser('super-admin');
    $post = kontenPost(['title' => 'Asli', 'slug' => 'asli', 'author_id' => $other->id]);

    Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
        ->fillForm(['title' => 'Baru'])
        ->call('save');

    expect($post->fresh())->author_id->toBe($other->id)->and($editor->id)->not->toBe($other->id);
});

it('VERIFY: lists albums, categories and videos without a query per row', function (string $page) {
    $this->actingAs(kontenUser('admin-konten'));
    $make = function (int $i): void {
        $album = kontenAlbum(['title' => "Album {$i}", 'category_id' => kontenCategory('album', "Kategori {$i}")->id]);
        kontenPhoto($album);
        kontenPhoto($album);
        kontenVideo(['title' => "Video {$i}"]);
        kontenPost(['title' => "Berita {$i}", 'category_id' => kontenCategory('post', "Post {$i}")->id])->delete();
    };

    foreach (range(1, 2) as $i) {
        $make($i);
    }

    Livewire::test($page);
    $small = dokumenQueryCount(fn () => Livewire::test($page));

    foreach (range(3, 12) as $i) {
        $make($i);
    }

    expect(dokumenQueryCount(fn () => Livewire::test($page)))->toBe($small);
})->with([ListAlbums::class, ManageCategories::class, ManageVideos::class]);

it('VERIFY: shows a soft-deleted post as deleted on the edit page and restores it from there', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $this->post->delete();

    Livewire::test(EditPost::class, ['record' => $this->post->getRouteKey()])
        ->assertActionVisible('restore')
        ->assertActionHidden('delete')
        ->callAction('restore');

    expect(Post::query()->find($this->post->id))->not->toBeNull();
});
