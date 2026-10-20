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

it('AC-32 (F037): has no bulk actions and offers the delete action on every row', function (string $role) {
    $this->actingAs(kontenUser($role));

    $component = Livewire::test(ListStaticPages::class)->assertCanSeeTableRecords(StaticPage::all());

    foreach (StaticPage::all() as $page) {
        $component->assertActionVisible(TestAction::make('delete')->table($page));
    }

    expect($component->instance()->getTable()->getFlatBulkActions())->toBe([]);
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

it('AC-11 (F048): the slug of a former system page is editable and can change', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(EditStaticPage::class, ['record' => $this->page->getRouteKey()])
        ->assertSchemaComponentExists('slug', checkComponentUsing: fn ($component): bool => ! $component->isDisabled())
        ->fillForm(['slug' => 'tentang-kami', 'title' => 'Profil'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->page->fresh()->slug)->toBe('tentang-kami');
});

it('AC-11 (F048)/AC-12 (F038): the template select has exactly three Indonesian options and saves the choice', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $labels = ['default' => __('pondok.static_page.templates.default'), 'contact' => __('pondok.static_page.templates.contact'), 'psb-info' => __('pondok.static_page.templates.psb-info')];

    Livewire::test(EditStaticPage::class, ['record' => $this->page->getRouteKey()])
        ->assertSchemaComponentExists('template', checkComponentUsing: fn ($component): bool => $component->getOptions() === $labels)
        ->fillForm(['template' => 'contact', 'title' => 'Profil'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($labels)->not->toContain('pondok.static_page.templates.default')->not->toContain('pondok.static_page.templates.contact')
        ->not->toContain('pondok.static_page.templates.psb-info')->and($labels['psb-info'])->toBe('Info PSB')
        ->and($this->page->fresh()->template)->toBe('contact');
});

it('AC-12 (F038): saves a page with the Info PSB template under the info-psb slug from the form', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(CreateStaticPage::class)
        ->fillForm(['title' => 'Informasi Pendaftaran', 'template' => 'psb-info', 'body' => '<p>Syarat</p>'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(StaticPage::query()->where('template', 'psb-info')->firstOrFail())->slug->toBe('info-psb');
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

it('AC-11 (F048): the table shows status and template, no system badge, and delete is available on all rows including the edit page', function (string $role) {
    $this->actingAs(kontenUser($role));
    $free = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus Yayasan', 'status' => 'draft']);

    $list = Livewire::test(ListStaticPages::class)
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('template')
        ->assertDontSeeText('Halaman sistem')
        ->assertSeeText(__('pondok.static_page.statuses.draft'))
        ->assertSeeText(__('pondok.static_page.statuses.terbit'))
        ->assertSeeText(__('pondok.static_page.templates.contact'));

    foreach (StaticPage::all() as $page) {
        $list->assertActionVisible(TestAction::make('delete')->table($page));
    }

    foreach (['profil', 'visi-misi', 'kontak'] as $slug) {
        Livewire::test(EditStaticPage::class, ['record' => StaticPage::findBySlug($slug)->getRouteKey()])->assertActionVisible('delete');
    }

    Livewire::test(EditStaticPage::class, ['record' => $free->getRouteKey()])->assertActionVisible('delete');
})->with(['admin-konten', 'super-admin']);

it('AC-6 (F048): deleting a former system page from the table removes it', function (string $role) {
    $this->actingAs(kontenUser($role));

    Livewire::test(ListStaticPages::class)->callAction(TestAction::make('delete')->table($this->page));

    expect(StaticPage::findBySlug('profil'))->toBeNull();
})->with(['admin-konten', 'super-admin']);

it('AC-33: deletes a free page after confirmation and names it in the notification', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $free = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus Yayasan']);
    navSet([navPage($free)]);
    $widgets = [ftr('links', ['items' => [navPage($free)]])];
    ftrSet($widgets);

    $component = Livewire::test(ListStaticPages::class)
        ->mountTableAction('delete', $free)
        ->assertActionMounted(TestAction::make('delete')->table($free))
        ->callMountedTableAction();

    expect(StaticPage::query()->whereKey($free->id)->exists())->toBeFalse()
        ->and(Setting::get('portal.navigation_primary'))->toBe([])
        ->and(ftrStored())->toEqual($widgets)
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

it('AC-10 (F048): the policy lets anyone with delete permission delete any page and nobody without it', function () {
    $policy = new StaticPagePolicy;
    $free = staticPage();

    foreach (['super-admin', 'admin-konten'] as $role) {
        foreach ([$this->page, $free, StaticPage::findBySlug('kontak')] as $page) {
            expect($policy->delete(kontenUser($role), $page))->toBeTrue("{$role} {$page->slug}");
        }
    }

    foreach (['bendahara', 'admin-sekolah', 'wali'] as $role) {
        expect($policy->delete(kontenUser($role), $this->page))->toBeFalse($role);
    }

    expect($policy->create(kontenUser('admin-konten')))->toBeTrue()
        ->and($policy->create(kontenUser('bendahara')))->toBeFalse()
        ->and($policy->deleteAny(kontenUser('admin-konten')))->toBeFalse();
});
