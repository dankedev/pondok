<?php

use App\Models\Category;
use App\Models\Setting;
use App\Models\StaticPage;
use Database\Seeders\ContentCategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\StaticPageSeeder;

it('AC-3: seeds the three static pages without overwriting edits', function () {
    $this->seed(StaticPageSeeder::class);

    expect(StaticPage::query()->count())->toBe(3)
        ->and(StaticPage::query()->orderBy('id')->pluck('title', 'slug')->all())->toBe([
            'profil' => 'Profil',
            'visi-misi' => 'Visi & Misi',
            'kontak' => 'Kontak',
        ])
        ->and(StaticPage::query()->pluck('body')->unique()->all())->toBe(['']);

    StaticPage::query()->where('slug', 'profil')->update(['body' => '<p>Sudah diisi</p>']);

    $this->seed(StaticPageSeeder::class);

    expect(StaticPage::query()->count())->toBe(3)
        ->and(StaticPage::query()->where('slug', 'profil')->value('body'))->toBe('<p>Sudah diisi</p>');
});

it('AC-3: seeds the default album categories idempotently', function () {
    $this->seed(ContentCategorySeeder::class);
    $this->seed(ContentCategorySeeder::class);

    expect(Category::query()->orderBy('name')->get(['type', 'name'])->map(fn (Category $c): array => [$c->type, $c->name])->all())
        ->toBe([['album', 'Fasilitas'], ['album', 'Kegiatan']]);
});

it('AC-3: calls both seeders from the database seeder', function () {
    $this->seed(DatabaseSeeder::class);

    expect(StaticPage::query()->pluck('slug')->sort()->values()->all())->toBe(['kontak', 'profil', 'visi-misi'])
        ->and(Category::query()->where('type', 'album')->pluck('name')->sort()->values()->all())->toBe(['Fasilitas', 'Kegiatan']);
});

it('AC-4: enables the konten module on a fresh install and keeps other defaults', function () {
    Setting::query()->delete();

    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('konten'))->toBeTrue()
        ->and(SettingSeeder::ENABLED_BY_DEFAULT)->toContain('konten')
        ->and(Setting::moduleEnabled('akademik'))->toBeTrue()
        ->and(Setting::moduleEnabled('asrama'))->toBeTrue()
        ->and(Setting::moduleEnabled('psb'))->toBeTrue()
        ->and(Setting::moduleEnabled('keuangan'))->toBeTrue()
        ->and(Setting::moduleEnabled('dokumen'))->toBeTrue()
        ->and(Setting::moduleEnabled('prestasi'))->toBeTrue();
});

it('AC-4: does not overwrite a konten switch that was turned off', function () {
    $this->seed(SettingSeeder::class);
    Setting::set('modules.konten.enabled', false);

    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('konten'))->toBeFalse();
});

it('AC-3 (F037): seeds system pages with draft profile and published contact', function () {
    $this->seed(StaticPageSeeder::class);

    $pages = StaticPage::query()->get()->keyBy('slug');

    expect($pages->keys()->sort()->values()->all())->toBe(['kontak', 'profil', 'visi-misi'])
        ->and($pages['profil']->status)->toBe('draft')
        ->and($pages['visi-misi']->status)->toBe('draft')
        ->and($pages['kontak']->status)->toBe('terbit')
        ->and($pages->pluck('title', 'slug')->all())->toBe([
            'profil' => __('pondok.static_page.defaults.profil'),
            'visi-misi' => __('pondok.static_page.defaults.visi-misi'),
            'kontak' => __('pondok.static_page.defaults.kontak'),
        ])
        ->and($pages->pluck('body')->unique()->all())->toBe([''])
        ->and(Setting::query()->whereIn('key', ['portal.navigation_primary', 'portal.navigation_footer', 'portal.header_button', 'portal.footer_text'])->count())->toBe(0);

    StaticPage::query()->where('slug', 'profil')->update(['status' => 'terbit', 'body' => '<p>Diisi</p>']);
    StaticPage::query()->where('slug', 'kontak')->update(['status' => 'draft']);

    $this->seed(StaticPageSeeder::class);

    expect(StaticPage::query()->where('slug', 'profil')->first())->status->toBe('terbit')->body->toBe('<p>Diisi</p>')
        ->and(StaticPage::query()->where('slug', 'kontak')->value('status'))->toBe('draft')
        ->and(Setting::query()->whereIn('key', ['portal.navigation_primary', 'portal.navigation_footer', 'portal.header_button', 'portal.footer_text'])->count())->toBe(0);
});
