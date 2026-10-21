<?php

use App\Filament\Pages\Site\Theme;
use App\Models\Setting;
use Livewire\Livewire;

/*
| F070 AC-14 dan AC-15. Kontrak halaman `App\Filament\Pages\Site\Theme` (slug `site/theme`, grup Situs, label "Tampilan", simpan lewat `save`).
| State form `data.primary`, `data.accent` (hex atau kosong), `data.font` (bawaan `jakarta`), `data.radius` (bawaan `medium`).
| Simpan memanggil `UpdatePortalTheme`; galat Action dipetakan ke `data.<field>`; notifikasi `pondok.school_profile.saved.theme`.
*/

beforeEach(function () {
    $this->f = kontenBoot();
});

it('AC-14: lets the profile roles open the theme page and forbids the rest', function () {
    foreach (['super-admin' => 200, 'admin-sekolah' => 200, 'admin-konten' => 200, 'bendahara' => 403, 'admin-psb' => 403, 'kesantrian' => 403, 'musyrif' => 403, 'petugas-pos' => 403, 'wali' => 403] as $role => $status) {
        test()->actingAs(kontenUser($role))->get('/admin/site/theme')->assertStatus($status, $role);
    }
});

it('AC-14: shows the Tampilan item in the menu of a profile role only', function () {
    expect(Theme::getNavigationLabel())->toBe('Tampilan')
        ->and(situsNavigationLabels(kontenUser('admin-konten')))->toContain('Tampilan')
        ->and(situsNavigationLabels(kontenUser('bendahara')))->not->toContain('Tampilan');
});

it('AC-15: shows the defaults when no theme key is set', function () {
    $this->actingAs(kontenUser('admin-konten'));

    $component = Livewire::test(Theme::class);

    expect(blank($component->get('data.primary')))->toBeTrue()
        ->and(blank($component->get('data.accent')))->toBeTrue()
        ->and($component->get('data.font'))->toBe('jakarta')
        ->and($component->get('data.radius'))->toBe('medium');
});

it('AC-15: fills the form from the stored settings', function () {
    themeSet(['primary' => '#1f2a5e', 'accent' => '#ffd452', 'font' => 'lora', 'radius' => 'small']);
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Theme::class)
        ->assertSet('data.primary', '#1f2a5e')
        ->assertSet('data.accent', '#ffd452')
        ->assertSet('data.font', 'lora')
        ->assertSet('data.radius', 'small');
});

it('AC-15: saves through the action and notifies', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Theme::class)
        ->set('data.primary', '#1F2A5E')
        ->set('data.accent', '#ffd452')
        ->set('data.font', 'nunito')
        ->set('data.radius', 'large')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified(__('pondok.school_profile.saved.theme'));

    expect(__('pondok.school_profile.saved.theme'))->toBe('Tampilan portal disimpan.')
        ->and(Setting::get('theme.primary'))->toBe('#1f2a5e')
        ->and(Setting::get('theme.font'))->toBe('nunito')
        ->and(kontenActivities()->where('description', 'portal_theme_updated'))->toHaveCount(1);
});

it('AC-15: shows the action error on the colour field and saves nothing', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Theme::class)
        ->set('data.primary', 'red')
        ->call('save')
        ->assertHasErrors(['data.primary']);

    expect(Setting::get('theme.primary'))->toBeNull()
        ->and(kontenActivities())->toHaveCount(0);
});

/*
| F072 AC-12. State `data.template` (bawaan `default`), Radio opsi `default`/`centered` dengan label dan deskripsi dari
| `pondok.school_profile.theme.templates.<kunci>`. Simpan tidak menyentuh `portal.home_blocks`.
*/

it('F072 AC-12: shows the default template and both options with their descriptions', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Theme::class)
        ->assertSet('data.template', 'default')
        ->assertSee('Standar (bawaan)')
        ->assertSee('Terpusat')
        ->assertSee(__('pondok.school_profile.theme.templates.default.description'))
        ->assertSee(__('pondok.school_profile.theme.templates.centered.description'));
});

it('F072 AC-12: saves the chosen template, notifies, and leaves the home blocks untouched', function () {
    blkSet([blk('hero', ['title' => 'Judul hero uji'])]);
    $blocks = blkStored();
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Theme::class)
        ->fillForm(['template' => 'centered'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified(__('pondok.school_profile.saved.theme'));

    expect(Setting::get('theme.template'))->toBe('centered')
        ->and(blkStored())->toBe($blocks);

    Livewire::test(Theme::class)->assertSet('data.template', 'centered');
});

it('F072 AC-12: rejects a template outside the options with an error on the field', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Theme::class)
        ->fillForm(['template' => 'tidak-ada'])
        ->call('save')
        ->assertHasErrors(['data.template']);

    expect(Setting::get('theme.template'))->toBeNull();
});
