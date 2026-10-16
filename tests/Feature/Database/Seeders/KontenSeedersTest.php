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
        ->and(Setting::moduleEnabled('prestasi'))->toBeFalse();
});

it('AC-4: does not overwrite a konten switch that was turned off', function () {
    $this->seed(SettingSeeder::class);
    Setting::set('modules.konten.enabled', false);

    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('konten'))->toBeFalse();
});
