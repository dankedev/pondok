<?php

use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Filament\Forms\Components\Select;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->f = prestasiFixture();
});

/**
 * Id prestasi yang ditawarkan Select `achievement_id` pada modal "Buat berita dari prestasi" yang terbuka.
 *
 * @return list<int>
 */
/**
 * User dengan izin eksplisit setara admin-konten, dikurangi satu izin (izin dari peran tidak bisa dicabut per user).
 */
function prestasiPostUserWithout(string $permission): User
{
    $permissions = Role::findByName('admin-konten')->permissions->pluck('name')
        ->reject(fn (string $name): bool => $name === $permission)->all();

    return User::factory()->create()->givePermissionTo($permissions);
}

function prestasiPostOptions(Testable $component): array
{
    $ids = [];

    $component->assertSchemaComponentExists('achievement_id', checkComponentUsing: function (Select $select) use (&$ids): bool {
        $options = $select->getOptions();

        try {
            $options += $select->getSearchResults('');
        } catch (Throwable) {
        }

        $ids = array_map('intval', array_keys($options));

        return true;
    });

    return $ids;
}

it('AC-34: creates a post from an achievement', function () {
    $f = $this->f;
    $ready = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true, 'title' => 'Juara 1 MTQ Tahfidz 10 Juz']);
    $private = prestasiCreate($f, [$f->s2], ['verified' => true, 'title' => 'Tidak publik']);
    $unverified = prestasiCreate($f, [$f->s2], ['public' => true, 'title' => 'Belum verifikasi']);
    $published = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true, 'title' => 'Sudah diberitakan']);
    kontenPost(['achievement_id' => $published->id, 'title' => 'Berita lama']);
    $deleted = prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true, 'title' => 'Berita dihapus']);
    kontenPost(['achievement_id' => $deleted->id, 'title' => 'Berita terhapus'])->delete();
    $this->actingAs($f->c);

    $options = prestasiPostOptions(Livewire::test(ListPosts::class)->assertActionVisible('fromAchievement')->mountAction('fromAchievement'));

    expect($options)->toBe([$ready->id])->not->toContain($private->id, $unverified->id, $published->id, $deleted->id);

    Livewire::test(ListPosts::class)
        ->callAction('fromAchievement', ['achievement_id' => $ready->id])
        ->assertNotified()
        ->assertRedirect();

    $post = Post::query()->where('achievement_id', $ready->id)->sole();

    expect($post->status)->toBe('draft')->and($post->type)->toBe('prestasi')->and($post->author_id)->toBe($f->c->id)
        ->and(Post::withTrashed()->count())->toBe(3);
});

it('AC-34: shows the option label with the title and the Indonesian date', function () {
    $f = $this->f;
    prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true, 'title' => 'Juara 1 MTQ Tahfidz 10 Juz']);
    $this->actingAs($f->c);
    $label = null;

    Livewire::test(ListPosts::class)->mountAction('fromAchievement')
        ->assertSchemaComponentExists('achievement_id', checkComponentUsing: function (Select $select) use (&$label): bool {
            $options = $select->getOptions() + (function () use ($select): array {
                try {
                    return $select->getSearchResults('');
                } catch (Throwable) {
                    return [];
                }
            })();
            $label = (string) collect($options)->first();

            return true;
        });

    expect($label)->toContain('Juara 1 MTQ Tahfidz 10 Juz')->toContain('17 Agu 2026');
});

it('AC-34: rejects an unpublishable achievement sent directly', function () {
    $f = $this->f;
    $unverified = prestasiCreate($f, [$f->s2], ['public' => true, 'title' => 'Belum verifikasi']);
    $this->actingAs($f->c);

    $component = Livewire::test(ListPosts::class)->callAction('fromAchievement', ['achievement_id' => $unverified->id]);

    expect(prestasiSeeMessage($component, 'not_publishable'))->toBeTrue()
        ->and(Post::withTrashed()->count())->toBe(0);
});

it('AC-34: hides the action when the prestasi module is off but keeps posts open', function () {
    Setting::set('modules.prestasi.enabled', false);
    $this->actingAs($this->f->c);

    Livewire::test(ListPosts::class)->assertSuccessful()->assertActionHidden('fromAchievement');

    $this->get('/admin/posts')->assertOk();
});

it('AC-34: hides the action without publish:achievement', function () {
    $user = prestasiPostUserWithout('publish:achievement');
    $this->actingAs($user);

    Livewire::test(ListPosts::class)->assertSuccessful()->assertActionHidden('fromAchievement');
});

it('AC-34: hides and refuses the action without create:post', function () {
    $f = $this->f;
    prestasiCreate($f, [$f->s2], ['verified' => true, 'public' => true]);
    $user = prestasiPostUserWithout('create:post');
    $this->actingAs($user);

    Livewire::test(ListPosts::class)->assertSuccessful()->assertActionHidden('fromAchievement')
        ->mountAction('fromAchievement')->assertActionNotMounted('fromAchievement');

    expect(Post::withTrashed()->count())->toBe(0);
});
