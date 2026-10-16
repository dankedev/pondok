<?php

use App\Filament\Resources\StaticPages\Pages\CreateStaticPage;
use App\Filament\Resources\StaticPages\Pages\EditStaticPage;
use App\Filament\Resources\StaticPages\Pages\ListStaticPages;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Policies\StaticPagePolicy;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
    $this->page = StaticPage::query()->where('slug', 'profil')->firstOrFail();
});

// F037: AC-32 F016 ("tidak bisa membuat atau menghapus") diganti: buat boleh, hapus hanya untuk halaman non-sistem.
it('AC-32 (F037): has no bulk actions and hides the delete action for system pages', function (string $role) {
    $this->actingAs(kontenUser($role));

    $component = Livewire::test(ListStaticPages::class)
        ->assertCanSeeTableRecords(StaticPage::all())
        ->assertActionHidden(TestAction::make('delete')->table($this->page));

    expect($component->instance()->getTable()->getFlatBulkActions())->toBe([]);
})->with(['admin-konten', 'super-admin']);

it('AC-32 (F037): has no delete action on the edit page of a system page', function (string $role) {
    $this->actingAs(kontenUser($role));

    Livewire::test(EditStaticPage::class, ['record' => $this->page->getRouteKey()])
        ->assertActionHidden('delete');
})->with(['admin-konten', 'super-admin']);

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

it('AC-32: content admin creates a page', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(CreateStaticPage::class)
        ->fillForm(['title' => 'Pengurus Yayasan', 'slug' => '', 'status' => 'terbit', 'body' => '<p>Isi pengurus</p>'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    expect(StaticPage::findBySlug('pengurus-yayasan'))->not->toBeNull()
        ->status->toBe('terbit');
});

it('AC-32: shows a reserved slug error on the form field', function () {
    $this->actingAs(kontenUser('admin-konten'));

    $component = Livewire::test(CreateStaticPage::class)
        ->fillForm(['title' => 'Berita Kami', 'slug' => 'berita', 'status' => 'terbit', 'body' => '<p>x</p>'])
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(kontenSeeMessage($component, 'slug_reserved'))->toBeTrue()
        ->and(StaticPage::query()->count())->toBe(3);
});

it('AC-33: shows status and system badges, and hides delete for system pages only', function (string $role) {
    $this->actingAs(kontenUser($role));
    $free = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus Yayasan', 'status' => 'draft']);

    $list = Livewire::test(ListStaticPages::class)
        ->assertTableColumnExists('status')
        ->assertSeeText(__('pondok.static_page.system_badge'))
        ->assertSeeText(__('pondok.static_page.statuses.draft'))
        ->assertSeeText(__('pondok.static_page.statuses.terbit'))
        ->assertActionVisible(TestAction::make('delete')->table($free));

    foreach (StaticPage::SYSTEM_SLUGS as $slug) {
        $list->assertActionHidden(TestAction::make('delete')->table(StaticPage::findBySlug($slug)));
    }

    expect(__('pondok.static_page.system_badge'))->not->toBe('pondok.static_page.system_badge');

    Livewire::test(EditStaticPage::class, ['record' => $free->getRouteKey()])->assertActionVisible('delete');

    foreach (StaticPage::SYSTEM_SLUGS as $slug) {
        Livewire::test(EditStaticPage::class, ['record' => StaticPage::findBySlug($slug)->getRouteKey()])
            ->assertActionHidden('delete')
            ->assertSchemaComponentExists('slug', checkComponentUsing: fn ($component): bool => $component->isDisabled());
    }
})->with(['admin-konten', 'super-admin']);

it('AC-33: a forced delete of a system page from the table changes nothing', function (string $role) {
    $this->actingAs(kontenUser($role));

    try {
        Livewire::test(ListStaticPages::class)->callTableAction('delete', $this->page);
    } catch (Throwable) {
        // aksi tersembunyi: boleh melempar
    }

    expect(StaticPage::findBySlug('profil'))->not->toBeNull();
})->with(['admin-konten', 'super-admin']);

it('AC-33: deletes a free page after confirmation and names it in the notification', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $free = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus Yayasan']);
    Setting::set('portal.footer_links', [['label' => null, 'page_id' => $free->id]]);

    $component = Livewire::test(ListStaticPages::class)
        ->mountTableAction('delete', $free)
        ->assertActionMounted(TestAction::make('delete')->table($free))
        ->callMountedTableAction();

    expect(StaticPage::query()->whereKey($free->id)->exists())->toBeFalse()
        ->and(Setting::get('portal.footer_links'))->toBeNull()
        ->and(asramaNotifications()->map(fn (array $n): string => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode(' '))->toContain('Pengurus Yayasan');
});

it('AC-34: forbids creating pages to the other roles and when the module is off', function (string $role) {
    $this->actingAs(kontenUser($role))->get('/admin/static-pages/create')->assertForbidden();
})->with(['bendahara', 'admin-psb', 'admin-sekolah', 'kesantrian', 'musyrif', 'petugas-pos', 'wali']);

it('AC-34: forbids the create page to content admins when the konten module is off', function () {
    Setting::set('modules.konten.enabled', false);

    $this->actingAs(kontenUser('admin-konten'))->get('/admin/static-pages/create')->assertForbidden();
});

it('AC-34: grants create and delete (but not delete-any) to content admins, idempotently', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    $names = kontenUser('admin-konten')->getAllPermissions()->pluck('name');

    expect($names->all())->toContain('create:static-page', 'delete:static-page')->not->toContain('delete-any:static-page')
        ->and(Permission::query()->where('name', 'delete-any:static-page')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'create:static-page')->count())->toBe(1)
        ->and(Permission::query()->where('name', 'delete:static-page')->count())->toBe(1);
});

it('AC-34: the policy denies deleting system pages even for a super admin when called directly', function () {
    $super = kontenUser('super-admin');
    $free = staticPage();

    expect((new StaticPagePolicy)->delete($super, $this->page))->toBeFalse()
        ->and((new StaticPagePolicy)->delete(kontenUser('admin-konten'), $free))->toBeTrue()
        ->and((new StaticPagePolicy)->create(kontenUser('admin-konten')))->toBeTrue()
        ->and((new StaticPagePolicy)->create(kontenUser('bendahara')))->toBeFalse()
        ->and((new StaticPagePolicy)->deleteAny(kontenUser('admin-konten')))->toBeFalse();
});
