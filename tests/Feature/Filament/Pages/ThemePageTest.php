<?php

use App\Actions\Konten\BuildColorScale;
use App\Actions\Konten\GetPortalTheme;
use App\Actions\Konten\ListPortalThemes;
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
| F072 AC-12, diperbarui F102. State `data.template` (bawaan `default`), Radio dari `ListPortalThemes` (nama dan deskripsi dari
| manifest, tema rusak nonaktif dengan alasan). Simpan tidak menyentuh `portal.home_blocks`.
*/

it('F102 AC-14: lists the four official themes with the name and description from their manifests', function () {
    $this->actingAs(kontenUser('admin-konten'));

    $component = Livewire::test(Theme::class)->assertSet('data.template', 'default');

    foreach (THEME_OFFICIAL as $slug) {
        $manifest = themeOfficialManifest($slug);
        $component->assertSee($manifest['name'])->assertSee($manifest['description']);
    }

    expectTranslatedKeys(['pondok.school_profile.theme.template', 'pondok.school_profile.theme.template_helper', 'pondok.school_profile.theme.template_unavailable']);
});

it('F102 AC-8: still renders when a theme is broken, shows the reason, and does not let it be chosen', function () {
    $slug = themeInstall([...themeBaseManifest(), 'schema_version' => 99]);
    $error = collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', $slug)['error'] ?? null;
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Theme::class)
        ->assertOk()
        ->assertSee($error)
        ->fillForm(['template' => $slug])
        ->call('save')
        ->assertHasErrors(['data.template']);

    expect($error)->toBeString()->not->toBe('')
        ->and(Setting::get('theme.template'))->toBeNull();
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

/*
| F071 AC-12, AC-13 (halaman). Pratinjau di bawah pemilih warna, terlihat dari state form (tanpa simpan), memakai
| `GetPortalTheme::brandTokens()`. Teks dari `pondok.school_profile.theme.brand_preview.{heading, button_sample, adjusted_button,
| adjusted_title, rejected}`; contoh tombol bertuliskan `button_sample` ("Contoh tombol").
*/

it('F071 AC-12: previews the button and title colours live from the form state', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $heading = __('pondok.school_profile.theme.brand_preview.heading');
    $component = Livewire::test(Theme::class)->assertDontSee($heading);

    $component->fillForm(['primary' => '#15803d'])
        ->assertSee($heading)->assertSee('Contoh tombol')->assertSee('#15803d')
        ->assertDontSee(__('pondok.school_profile.theme.brand_preview.adjusted_button'))
        ->assertDontSee(__('pondok.school_profile.theme.brand_preview.adjusted_title'));

    $light = GetPortalTheme::brandTokens('#facc15');

    $component->fillForm(['primary' => '#facc15'])
        ->assertSee(__('pondok.school_profile.theme.brand_preview.adjusted_title'))
        ->assertSee($light['brand_text'])
        ->assertDontSee(__('pondok.school_profile.theme.brand_preview.adjusted_button'));

    $component->fillForm(['primary' => '#777777'])
        ->assertSee(__('pondok.school_profile.theme.brand_preview.adjusted_button'));

    $component->fillForm(['primary' => 'red'])
        ->assertDontSee($heading)->assertDontSee('Contoh tombol')->assertHasNoErrors();
});

it('F071 AC-13: shows the rejected message instead of a sample when the portal would refuse the colour', function () {
    app()->bind(BuildColorScale::class, fn () => new class extends BuildColorScale
    {
        public function handle(string $hex, string $role): array
        {
            return [...THEME_DEFAULT_PRIMARY, 900 => '#777777'];
        }
    });
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Theme::class)
        ->fillForm(['primary' => '#15803d'])
        ->assertSee(__('pondok.school_profile.theme.brand_preview.rejected'))
        ->assertDontSee('Contoh tombol');
});

it('F104 AC-8: tells the admin that default colours and font follow the active theme', function () {
    $this->actingAs(kontenUser('admin-konten'));

    $html = mb_strtolower(Livewire::test(Theme::class)->html());

    expect($html)->toContain('mengikuti tema');
});
