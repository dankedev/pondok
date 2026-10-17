<?php

use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Post;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->editor = kontenUser('admin-konten');
    $this->actingAs($this->editor);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kontenPostForm(array $overrides = []): array
{
    return [
        'title' => 'Wisuda Tahfidz 2026',
        'body' => '<p>Alhamdulillah</p>',
        'status' => 'draft',
        ...$overrides,
    ];
}

it('AC-19: creates a post through the form', function () {
    Livewire::test(CreatePost::class)
        ->fillForm(kontenPostForm(['excerpt' => 'Ringkasan', 'cover' => kontenImage('sampul.jpg')]))
        ->call('create')
        ->assertHasNoFormErrors();

    $post = Post::query()->sole();

    expect($post)->title->toBe('Wisuda Tahfidz 2026')->slug->toBe('wisuda-tahfidz-2026')->author_id->toBe($this->editor->id)
        ->status->toBe('draft')->excerpt->toBe('Ringkasan')
        ->and($post->cover_path)->toStartWith('posts/covers/')
        ->and(Storage::disk('public')->exists($post->cover_path))->toBeTrue();
});

it('AC-19: publishes with the current time when the date is left empty', function () {
    Livewire::test(CreatePost::class)
        ->fillForm(kontenPostForm(['status' => 'terbit', 'published_at' => null]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Post::query()->sole()->published_at->format('Y-m-d H:i'))->toBe('2026-10-15 10:00');
});

it('AC-19: shows slug_taken on the slug field and creates nothing', function () {
    kontenPost(['title' => 'Lama', 'slug' => 'dipakai']);

    $component = Livewire::test(CreatePost::class)
        ->fillForm(kontenPostForm(['slug' => 'dipakai']))
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(kontenSeeMessage($component, 'slug_taken'))->toBeTrue()
        ->and(Post::query()->count())->toBe(1);
});

it('AC-19: shows body and cover errors on their fields', function () {
    $component = Livewire::test(CreatePost::class)
        ->fillForm(kontenPostForm(['body' => '<p>x</p><img src="data:image/png;base64,iVBORw0KGgo=">']))
        ->call('create')
        ->assertHasFormErrors(['body']);

    expect(kontenSeeMessage($component, 'media_source_invalid'))->toBeTrue()
        ->and(Post::query()->count())->toBe(0);
});

it('AC-19: requires title, body and status', function () {
    Livewire::test(CreatePost::class)
        ->fillForm(['title' => null, 'body' => null, 'status' => null])
        ->call('create')
        ->assertHasFormErrors(['title', 'body', 'status']);

    expect(Post::query()->count())->toBe(0);
});

it('AC-19: offers only post categories and creates a post in one', function () {
    $post = kontenCategory('post', 'Pengumuman');
    $album = kontenCategory('album', 'Fasilitas');

    Livewire::test(CreatePost::class)
        ->assertSchemaComponentExists('category_id', checkComponentUsing: fn ($select): bool => array_keys($select->getOptions()) === [$post->id])
        ->fillForm(kontenPostForm(['category_id' => $post->id]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Post::query()->sole()->category_id)->toBe($post->id)->and($album->id)->not->toBe($post->id);
});

it('AC-19: edits a post, keeps the author and updates the slug only when given', function () {
    $other = kontenUser('super-admin');
    $post = kontenPost(['title' => 'Asli', 'slug' => 'asli', 'author_id' => $other->id]);

    Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
        ->fillForm(['title' => 'Judul baru', 'body' => '<p>Isi baru</p>'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($post->fresh())->title->toBe('Judul baru')->body->toBe('<p>Isi baru</p>')->author_id->toBe($other->id)->slug->toBe('asli');
});

it('AC-19: removes the cover from the edit form', function () {
    $post = kontenPost(['cover_path' => 'posts/covers/lama.jpg']);
    Storage::disk('public')->put('posts/covers/lama.jpg', 'gambar');

    Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
        ->fillForm(['remove_cover' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($post->fresh()->cover_path)->toBeNull()->and(Storage::disk('public')->exists('posts/covers/lama.jpg'))->toBeFalse();
});

it('AC-20: shows draft, published and scheduled status plus Umum for posts without a unit', function () {
    $draft = kontenPost(['title' => 'Draf', 'status' => 'draft']);
    $published = kontenPost(['title' => 'Terbit', 'status' => 'terbit', 'published_at' => '2026-10-14 08:00:00']);
    $scheduled = kontenPost(['title' => 'Terjadwal', 'status' => 'terbit', 'published_at' => '2026-10-20 08:00:00', 'unit_id' => $this->f->sd->id]);

    $component = Livewire::test(ListPosts::class)
        ->assertCanSeeTableRecords([$draft, $published, $scheduled])
        ->assertTableColumnFormattedStateSet('status', 'Draft', record: $draft)
        ->assertTableColumnFormattedStateSet('status', 'Terbit', record: $published)
        ->assertTableColumnFormattedStateSet('status', 'Terjadwal', record: $scheduled);

    expect(tagihanPlainText($component))->toContain('Umum')->toContain($this->f->sd->name);
});

it('AC-20: hides soft-deleted posts unless the trashed filter asks for them', function () {
    $live = kontenPost(['title' => 'Hidup']);
    $trashed = kontenPost(['title' => 'Terhapus']);
    $trashed->delete();

    Livewire::test(ListPosts::class)
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$trashed])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$live, $trashed]);
});

it('AC-6: has no type field, column or filter', function () {
    Livewire::test(CreatePost::class)->assertSchemaComponentDoesNotExist('type');

    $table = Livewire::test(ListPosts::class)->instance()->getTable();

    expect($table->getColumn('type'))->toBeNull()
        ->and($table->getFilter('type'))->toBeNull()
        ->and($table->getColumn('category.name'))->not->toBeNull()
        ->and($table->getFilter('category_id'))->not->toBeNull();
});

it('AC-6: filters by category and status and searches by title', function () {
    $category = kontenCategory('post', 'Kegiatan');
    $news = kontenPost(['title' => 'Berita satu']);
    $activity = kontenPost(['title' => 'Kegiatan satu', 'category_id' => $category->id, 'status' => 'terbit', 'published_at' => '2026-10-14 08:00:00']);

    Livewire::test(ListPosts::class)
        ->filterTable('category_id', $category->id)
        ->assertCanSeeTableRecords([$activity])->assertCanNotSeeTableRecords([$news])
        ->removeTableFilter('category_id')
        ->filterTable('status', 'draft')
        ->assertCanSeeTableRecords([$news])->assertCanNotSeeTableRecords([$activity])
        ->removeTableFilter('status')
        ->searchTable('Kegiatan')
        ->assertCanSeeTableRecords([$activity])->assertCanNotSeeTableRecords([$news]);
});

it('AC-20: has no bulk actions', function () {
    $table = Livewire::test(ListPosts::class)->instance()->getTable();

    expect($table->getFlatBulkActions())->toBe([]);
});

it('AC-20: shows the empty state with a create action', function () {
    $component = Livewire::test(ListPosts::class);

    expect(tagihanPlainText($component))->toContain('Belum ada berita atau kegiatan.');
});

it('AC-20: loads the list without a query per row', function () {
    foreach (range(1, 3) as $i) {
        kontenPost(['title' => "Awal {$i}", 'author_id' => $this->editor->id, 'unit_id' => $this->f->sd->id, 'category_id' => kontenCategory('post', "K{$i}")->id]);
    }

    // Pemanasan: cache permission dan saklar terisi pada render pertama.
    Livewire::test(ListPosts::class);

    $small = dokumenQueryCount(fn () => Livewire::test(ListPosts::class));

    foreach (range(1, 12) as $i) {
        kontenPost(['title' => "Tambah {$i}", 'author_id' => $this->editor->id, 'unit_id' => $this->f->sltp->id, 'category_id' => kontenCategory('post', "L{$i}")->id]);
    }

    $large = dokumenQueryCount(fn () => Livewire::test(ListPosts::class));

    expect($large)->toBe($small);
});

it('AC-21: soft deletes a post from the edit page and keeps its cover file', function () {
    $post = kontenPost(['cover_path' => 'posts/covers/a.jpg']);
    Storage::disk('public')->put('posts/covers/a.jpg', 'gambar');

    Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
        ->assertActionVisible('delete')
        ->assertActionHidden('restore')
        ->callAction('delete');

    expect(Post::withTrashed()->find($post->id)->deleted_at)->not->toBeNull()
        ->and(Storage::disk('public')->exists('posts/covers/a.jpg'))->toBeTrue();
});

it('AC-21: restores a soft-deleted post from the edit page', function () {
    $post = kontenPost();
    $post->delete();

    Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
        ->assertActionVisible('restore')
        ->assertActionHidden('delete')
        ->callAction('restore');

    expect(Post::query()->find($post->id))->not->toBeNull();
});

it('AC-21: deletes and restores from the list row actions', function () {
    $post = kontenPost(['title' => 'Baris']);

    Livewire::test(ListPosts::class)
        ->assertActionVisible(TestAction::make('delete')->table($post))
        ->callAction(TestAction::make('delete')->table($post));

    expect(Post::query()->find($post->id))->toBeNull();

    Livewire::test(ListPosts::class)
        ->filterTable('trashed', false)
        ->assertActionVisible(TestAction::make('restore')->table($post))
        ->callAction(TestAction::make('restore')->table($post));

    expect(Post::query()->find($post->id))->not->toBeNull();
});

it('AC-21: hides restore without the restore permission', function () {
    $user = dashboardUserWith(['view-any:post', 'view:post', 'update:post', 'delete:post']);
    $post = kontenPost();
    $post->delete();
    $this->actingAs($user);

    Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
        ->assertActionHidden('restore');

    expect(Post::withTrashed()->find($post->id)->trashed())->toBeTrue();
});

it('AC-21: hides delete without the delete permission', function () {
    $user = dashboardUserWith(['view-any:post', 'view:post', 'update:post']);
    $post = kontenPost();
    $this->actingAs($user);

    Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
        ->assertActionHidden('delete');
});

it('AC-21: forbids a user without post permissions from the post pages', function () {
    $post = kontenPost();
    $this->actingAs(kontenUser('bendahara'));

    $this->get('/admin/posts')->assertForbidden();
    $this->get("/admin/posts/{$post->id}/edit")->assertForbidden();
    $this->get('/admin/posts/create')->assertForbidden();
});

it('AC-21: opens a soft-deleted post on the edit page', function () {
    $post = kontenPost();
    $post->delete();

    $this->get("/admin/posts/{$post->id}/edit")->assertOk();
});
